<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Votepit\Domain\AccountPlanResolver;
use Votepit\Domain\PlanPolicy;
use Votepit\Domain\ResolvedPlan;
use Votepit\Http\Middleware\AccountContextMiddleware;
use Votepit\Http\Middleware\AuthNMiddleware;
use Votepit\Http\Support\FrozenBoardGuard;
use Votepit\Logging\AuditLogger;
use Votepit\Persistence\BoardAssetRepository;
use Votepit\Persistence\BoardRepository;
use Votepit\Persistence\UploadTermsConsentRepository;
use Votepit\Security\BoardAssetFileStore;
use Votepit\Security\BoardAssetProcessor;
use Votepit\Security\BoardAssetRendition;

/**
 * Board imagery (P-28): cover, favicon and share image.
 *
 * GET    /admin/boards/{slug}/assets                 — list (AuthZ: accountAdmin)
 * POST   /admin/boards/{slug}/assets/{kind}          — upload + re-encode (accountAdmin, rate limited)
 * POST   /admin/boards/{slug}/assets/{kind}/delete   — remove (accountAdmin)
 * GET    /{board}/assets/{publicId}                  — public image (AuthZ: anon)
 *
 * Plan gate (PlanPolicy, server side): `cover_upload` for the cover,
 * `favicon_og` for favicon and share image, `logo_url` (Lite+) for the logo
 * (square app symbol) and the wordmark (wide topbar logo).
 * Uploading needs the gate; deleting never does (clearing is always allowed,
 * like the branding fields). A
 * downgrade keeps the stored files and rows: the public endpoint and the public
 * board payload re-check the CURRENT plan and stop serving them, a re-upgrade
 * restores them without any action.
 *
 * The logo is a 256x256 PNG rendition (transparent, whole logo fitted); the
 * upload limit for it is 2 MB, the others use the instance limit. The SPA
 * shows the rendition through the same gated endpoint as every other image.
 *
 * Delivery: the bytes are only ever what BoardAssetProcessor produced. The
 * Content-Type is the fixed value stored for that variant, never derived from
 * a name or the request. Served with nosniff, inline disposition and an
 * immutable cache lifetime; a re-upload gets a new public id, so an old URL
 * can never serve new bytes.
 *
 * Kill switch (Config::$boardUploadsEnabled): while off, uploads answer 403
 * board_uploads_unavailable and the public endpoint answers 404 for every image,
 * as if it were absent. Deleting stays allowed, so an owner can still clear an
 * image. Stored files and rows are kept and come back when the switch is on.
 *
 * Account scoping: every route resolves the board through the account-scoped
 * lookup first. The public endpoint serves a public id only for the board in
 * the URL, so a public id of another board (or account) is a 404 by
 * construction.
 */
final readonly class BoardAssetAction
{
    /** Cache lifetime for the public image: a re-upload changes the URL, so the bytes behind it never change. */
    public const IMMUTABLE_CACHE_CONTROL = 'public, max-age=31536000, immutable';

    public function __construct(
        private BoardRepository $boardRepo,
        private AccountPlanResolver $plans,
        private BoardAssetRepository $assets,
        private BoardAssetFileStore $files,
        private BoardAssetProcessor $processor,
        private PlanPolicy $planPolicy,
        private AuditLogger $audit,
        private UploadTermsConsentRepository $uploadTerms,
        private ?string $uploadTermsVersion,
        // Installation kill switch (Config::$boardUploadsEnabled). Off: uploads
        // are refused and the public endpoint serves nothing (fail-secure).
        private bool $boardUploadsEnabled = true,
    ) {}

    /** Gate that governs a kind (upload side). */
    public static function gateForKind(string $kind): string
    {
        return match ($kind) {
            BoardAssetProcessor::KIND_COVER => 'cover_upload',
            BoardAssetProcessor::KIND_LOGO, BoardAssetProcessor::KIND_WORDMARK, BoardAssetProcessor::KIND_WORDMARK_LIGHT => 'logo_url',
            default                                                             => 'favicon_og',
        };
    }

    /** Gate that governs a stored variant (public serving side). */
    public static function gateForVariant(string $variant): string
    {
        return match (true) {
            str_starts_with($variant, 'cover_')                 => 'cover_upload',
            $variant === BoardAssetProcessor::VARIANT_LOGO,
            $variant === BoardAssetProcessor::VARIANT_WORDMARK || $variant === BoardAssetProcessor::VARIANT_WORDMARK_LIGHT => 'logo_url',
            default                                             => 'favicon_og',
        };
    }

    // -------------------------------------------------------------------------
    // GET /admin/boards/{slug}/assets
    // -------------------------------------------------------------------------

    /** @return array<string, array{version: string, accepted: bool}> */
    private function uploadTermsBlock(int $accountId): array
    {
        $version = $this->uploadTermsVersion;
        if ($version === null) {
            return [];
        }

        return ['upload_terms' => [
            'version'  => $version,
            'accepted' => $this->uploadTerms->acceptedAt($accountId, $version) !== null,
        ]];
    }

    /** @param array<string, mixed> $args */
    public function list(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $accountId = (int) $request->getAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID);
        $board     = $this->boardRepo->findBySlugForAccount($this->slugArg($args), $accountId);
        if (!is_array($board)) {
            return $this->notFound($response);
        }

        $plan = $this->planFor($accountId, $request);

        $variants = [];
        foreach ($this->assets->listForBoard((int) $board['id']) as $row) {
            $variants[$row['variant']] = [
                'id'     => $row['public_id'],
                'width'  => $row['width'],
                'height' => $row['height'],
                'ink'    => $row['ink_luminance'],
            ];
        }

        return $this->json($response, 200, [
            'variants' => $variants,
            'allowed'  => [
                'cover_upload'     => $this->planPolicy->isBrandingFieldAllowed($plan, 'cover_upload'),
                'favicon_og'       => $this->planPolicy->isBrandingFieldAllowed($plan, 'favicon_og'),
                'back_link_footer' => $this->planPolicy->isBrandingFieldAllowed($plan, 'back_link_footer'),
                'logo_url'         => $this->planPolicy->isBrandingFieldAllowed($plan, 'logo_url'),
            ],
            // Upload clause (cloud only): present only when a terms version is configured.
            ...$this->uploadTermsBlock($accountId),
            'max_upload_bytes' => $this->processor->maxUploadBytes(),
            'max_upload_bytes_by_kind' => array_combine(
                BoardAssetProcessor::KINDS,
                array_map($this->processor->maxUploadBytesFor(...), BoardAssetProcessor::KINDS),
            ),
            // Stored values, shown to the admin even when the plan locks them (Q19).
            'link'             => [
                'back_link_url'   => $board['back_link_url'] ?? '',
                'back_link_label' => $board['back_link_label'] ?? '',
                'footer_note'     => $board['footer_note'] ?? '',
            ],
        ]);
    }

    // -------------------------------------------------------------------------
    // POST /admin/boards/{slug}/assets/{kind}
    // -------------------------------------------------------------------------

    /** @param array<string, mixed> $args */
    public function upload(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $accountId = (int) $request->getAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID);
        $board     = $this->boardRepo->findBySlugForAccount($this->slugArg($args), $accountId);
        if (!is_array($board)) {
            return $this->notFound($response);
        }
        if (!$this->boardUploadsEnabled) {
            return $this->error($response, 403, 'board_uploads_unavailable', 'Image uploads are not available on this installation.');
        }
        if (FrozenBoardGuard::isFrozen($board)) {
            return FrozenBoardGuard::reject($response);
        }

        $kind = is_string($args['kind'] ?? null) ? $args['kind'] : '';
        if (!in_array($kind, BoardAssetProcessor::KINDS, true)) {
            return $this->notFound($response);
        }

        $plan = $this->planFor($accountId, $request);
        if (!$this->planPolicy->isBrandingFieldAllowed($plan, self::gateForKind($kind))) {
            return $this->error($response, 403, 'plan_feature_locked', 'This image is not available on your current plan. Please upgrade.');
        }

        // Upload clause (AGB 19, fail-secure): no file is read before the
        // account accepted the current version. A bump of the version asks again.
        $termsVersion = $this->uploadTermsVersion;
        if ($termsVersion !== null && $this->uploadTerms->acceptedAt($accountId, $termsVersion) === null) {
            return $this->error($response, 403, 'upload_terms_required', 'Please accept the upload terms before uploading images.');
        }

        $uploads = $request->getUploadedFiles();
        $file    = $uploads['image'] ?? null;
        if (!$file instanceof UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) {
            return $this->error($response, 422, 'invalid_upload', 'No valid file was uploaded.');
        }
        $limit = $this->processor->maxUploadBytesFor($kind);
        if ($file->getSize() !== null && $file->getSize() > $limit) {
            return $this->error($response, 422, 'file_too_large', 'The file exceeds the size limit.');
        }

        $raw = (string) $file->getStream();
        if (strlen($raw) > $limit) {
            return $this->error($response, 422, 'file_too_large', 'The file exceeds the size limit.');
        }

        $renditions = $this->processor->process($raw, $kind);
        if ($renditions === null) {
            // Only file properties are named (format, size); server details never are.
            // Everything else (SVG, HTML, polyglots, zero bytes, corrupt files) stays generic.
            $reason = $this->processor->rejectionReason($raw, $kind);
            if ($reason === 'file_too_large') {
                return $this->error($response, 422, 'file_too_large', 'The file exceeds the size limit.');
            }
            if ($reason === 'unsupported_format') {
                return $this->error($response, 422, 'unsupported_format', 'Only PNG, JPEG or WebP images are accepted.');
            }
            if ($reason === 'image_too_small') {
                return $this->error($response, 422, 'image_too_small', 'The image is too small.');
            }

            return $this->error($response, 422, 'invalid_image', 'The file could not be processed as an image.');
        }

        $boardId = (int) $board['id'];
        $rows    = [];
        $written = [];
        foreach ($renditions as $rendition) {
            $publicId = bin2hex(random_bytes(16));
            $fileName = $this->files->write($publicId, $rendition->extension, $rendition->bytes);
            if ($fileName === null) {
                foreach ($written as $partial) {
                    $this->files->delete($partial);
                }

                return $this->error($response, 500, 'storage_error', 'Could not save the image.');
            }
            $written[] = $fileName;
            $rows[]    = $this->rowFor($rendition, $publicId, $fileName);
        }

        // New files are on disk first, the DB swap follows, old files go last:
        // the row never points at a file that does not exist yet.
        try {
            $replaced = $this->assets->replace($boardId, $rows, new \DateTimeImmutable());
        } catch (\Throwable $e) {
            foreach ($written as $partial) {
                $this->files->delete($partial);
            }
            throw $e;
        }
        foreach ($replaced as $old) {
            $this->files->delete($old);
        }

        $this->audit->log('board.asset_uploaded', ['board_id' => $boardId, 'kind' => $kind]);

        $ids = [];
        foreach ($rows as $row) {
            $ids[$row['variant']] = $row['public_id'];
        }

        return $this->json($response, 200, ['ok' => true, 'variants' => $ids]);
    }

    // -------------------------------------------------------------------------
    // POST /admin/boards/{slug}/assets/{kind}/delete
    // -------------------------------------------------------------------------

    /** @param array<string, mixed> $args */
    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $accountId = (int) $request->getAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID);
        $board     = $this->boardRepo->findBySlugForAccount($this->slugArg($args), $accountId);
        if (!is_array($board)) {
            return $this->notFound($response);
        }
        if (FrozenBoardGuard::isFrozen($board)) {
            return FrozenBoardGuard::reject($response);
        }

        $kind = is_string($args['kind'] ?? null) ? $args['kind'] : '';
        if (!array_key_exists($kind, BoardAssetProcessor::KIND_VARIANTS)) {
            return $this->notFound($response);
        }

        $boardId = (int) $board['id'];
        $files   = $this->assets->deleteVariants($boardId, BoardAssetProcessor::KIND_VARIANTS[$kind]);
        foreach ($files as $fileName) {
            $this->files->delete($fileName);
        }

        $this->audit->log('board.asset_removed', ['board_id' => $boardId, 'kind' => $kind]);

        return $this->json($response, 200, ['ok' => true]);
    }

    // -------------------------------------------------------------------------
    // GET /{board}/assets/{publicId}
    // -------------------------------------------------------------------------

    /** @param array<string, mixed> $args */
    public function serve(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $accountId = (int) $request->getAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID);
        $slug      = is_string($args['board'] ?? null) ? $args['board'] : '';
        $publicId  = is_string($args['id'] ?? null) ? $args['id'] : '';
        if (preg_match('/^[0-9a-f]{32}$/', $publicId) !== 1) {
            return $response->withStatus(404);
        }
        // Kill switch: stored images are treated as absent while uploads are off.
        if (!$this->boardUploadsEnabled) {
            return $response->withStatus(404);
        }

        $board = $this->boardRepo->findPublicBySlugForAccount($slug, $accountId, false);
        if (!is_array($board)) {
            return $response->withStatus(404);
        }

        $asset = $this->assets->findByPublicIdForBoard((int) $board['id'], $publicId);
        if ($asset === null) {
            return $response->withStatus(404);
        }

        // Downgrade: the stored image stays, but is not served publicly until
        // the plan allows it again.
        $plan = $this->plans->forAccount($accountId, null);
        if (!$this->planPolicy->isBrandingFieldAllowed($plan, self::gateForVariant($asset['variant']))) {
            return $response->withStatus(404);
        }

        $bytes = $this->files->read($asset['file_name']);
        if ($bytes === null) {
            return $response->withStatus(404);
        }

        $response->getBody()->write($bytes);

        return $response
            ->withStatus(200)
            ->withHeader('Content-Type', $asset['content_type'])
            ->withHeader('Content-Length', (string) strlen($bytes))
            ->withHeader('Content-Disposition', 'inline')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Cache-Control', self::IMMUTABLE_CACHE_CONTROL);
    }

    /** @return array{variant: string, public_id: string, file_name: string, content_type: string, width: int, height: int, ink_luminance: ?float} */
    private function rowFor(BoardAssetRendition $rendition, string $publicId, string $fileName): array
    {
        return [
            'variant'       => $rendition->variant,
            'public_id'     => $publicId,
            'file_name'     => $fileName,
            'content_type'  => $rendition->contentType,
            'width'         => $rendition->width,
            'height'        => $rendition->height,
            'ink_luminance' => $rendition->inkLuminance,
        ];
    }

    /** @param array<string, mixed> $args */
    private function slugArg(array $args): string
    {
        return is_string($args['slug'] ?? null) ? $args['slug'] : '';
    }

    private function planFor(int $accountId, ServerRequestInterface $request): ResolvedPlan
    {
        return $this->plans->forAccount($accountId, $request->getAttribute(AuthNMiddleware::ATTR_USER));
    }

    private function notFound(ResponseInterface $response): ResponseInterface
    {
        return $this->error($response, 404, 'not_found', 'Board not found.');
    }

    private function error(ResponseInterface $response, int $status, string $key, string $message): ResponseInterface
    {
        return $this->json($response, $status, ['error' => ['key' => $key, 'message' => $message]]);
    }

    /** @param array<string, mixed> $payload */
    private function json(ResponseInterface $response, int $status, array $payload): ResponseInterface
    {
        $response->getBody()->write((string) json_encode($payload));

        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
