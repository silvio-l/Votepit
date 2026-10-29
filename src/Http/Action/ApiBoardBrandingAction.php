<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Votepit\Domain\AccountPlanResolver;
use Votepit\Domain\ResolvedPlan;
use Votepit\Http\Middleware\AccountContextMiddleware;
use Votepit\Http\Middleware\ApiTokenAuthMiddleware;
use Votepit\Http\Support\FrozenBoardGuard;
use Votepit\Logging\AuditLogger;
use Votepit\Persistence\BoardRepository;
use Votepit\Security\ApiTokenScope;
use Votepit\Security\BoardAssetProcessor;

/**
 * Agent API board branding (migration 0117, `manage` grant):
 *
 *   GET    /api/v1/board/branding              read    (branding, pro_branding, assets)
 *   PATCH  /api/v1/board/branding              manage  (partial update, same fields as the app)
 *   POST   /api/v1/board/assets/{kind}         manage  (multipart `image`)
 *   DELETE /api/v1/board/assets/{kind}         manage
 *
 * and the MCP tools get_board_branding, update_board_branding,
 * upload_board_asset (base64) and delete_board_asset.
 *
 * No duplicated rules: every write is validated by the session actions
 * (BoardBrandingAction and BoardProBrandingAction prepare()/commit(),
 * BoardAssetAction uploadRefusal()/storeImage()/removeKind()), so the plan
 * gates, the contrast check, the Q19 downgrade rule, the upload clause, the
 * size limits, the image processing and the audit entries are the app's.
 * API-specific parts: the grant check, the issuing user as the acting user for
 * the plan, the PATCH merge with the stored values (see update()), the base64
 * transport for MCP and the `api_token.write` audit entry.
 *
 * The `manage` grant is enforced by the middleware downgrade (an issuer who is
 * no longer owner/admin gets `moderate`), so a 403 here means a read or
 * moderate grant. The API never accepts the upload clause: an upload is refused
 * with upload_terms_required until the account accepted it in the app.
 *
 * A frozen board refuses every write here (423 board_frozen). The app still
 * lets the owner clear images on a private frozen board; the API does not.
 */
final readonly class ApiBoardBrandingAction
{
    /** Image types accepted for MCP base64 uploads (the processor checks the bytes anyway). */
    private const MCP_CONTENT_TYPES = ['image/png', 'image/jpeg', 'image/webp'];

    public function __construct(
        private BoardRepository $boardRepo,
        private AccountPlanResolver $plans,
        private BoardBrandingAction $branding,
        private BoardProBrandingAction $proBranding,
        private BoardAssetAction $assets,
        private BoardAssetProcessor $processor,
        private AuditLogger $audit,
    ) {}

    // -------------------------------------------------------------------------
    // Core (shared by the REST routes and the MCP tools)
    // -------------------------------------------------------------------------

    /** @return array{status: int, body: array<string, mixed>} */
    public function view(ServerRequestInterface $request): array
    {
        $ctx = $this->context($request, ApiTokenScope::READ);
        if (isset($ctx['status'])) {
            return $ctx;
        }
        $plan    = $this->planFor($ctx['token']);
        $boardId = (int) $ctx['board']['id'];

        return ['status' => 200, 'body' => [
            'branding'     => $this->branding->describe($ctx['board'], $plan),
            'pro_branding' => $this->proBranding->describe($ctx['board'], $plan),
            'assets'       => $this->assets->assetView($boardId, $plan),
        ]];
    }

    /**
     * Partial branding update. The app's basic form is a full save (an absent
     * primary_color, secondary_color, logo_url, intro or hide_badge resets to
     * the default), so the stored sanitized values are the baseline for those
     * keys and the body overrides them. Every other key already keeps its
     * stored value when absent (Q19 rules in the session actions).
     *
     * @param mixed $parsed the decoded JSON body
     * @return array{status: int, body: array<string, mixed>}
     */
    public function update(ServerRequestInterface $request, mixed $parsed): array
    {
        $ctx = $this->context($request, ApiTokenScope::MANAGE);
        if (isset($ctx['status'])) {
            return $ctx;
        }
        $token     = $ctx['token'];
        $board     = $ctx['board'];
        $accountId = $token['account_id'];

        if (FrozenBoardGuard::isFrozen($board)) {
            return $this->failure(423, 'board_frozen', 'This board is frozen (read-only) and cannot be edited at the moment.');
        }

        $plan = $this->planFor($token);
        $body = is_array($parsed) ? $parsed : [];

        $basic = $this->branding->prepare($board, $accountId, $this->withStoredBaseline($board, $plan, $body), $plan);
        $pro   = $this->proBranding->prepare($board, $accountId, $body, $plan);

        $errors = [...$basic['errors'], ...$pro['errors']];
        if ($errors !== []) {
            $locked = [...$basic['locked'], ...$pro['locked']];

            return ['status' => 422, 'body' => ['error' => $this->validationError($errors, $locked)]];
        }

        $this->branding->commit($board, $accountId, $basic['write']);
        $this->proBranding->commit($board, $accountId, $pro['write']);
        $this->recordWrite($token, (int) $board['id'], 'branding_update', $request, 200);

        return ['status' => 200, 'body' => ['ok' => true]];
    }

    /**
     * Everything that refuses an upload before any byte is read (grant, kill
     * switch, frozen board, unknown kind, plan gate, upload clause). Null when
     * the upload may proceed.
     *
     * @return array{status: int, body: array<string, mixed>}|null
     */
    public function assetRefusal(ServerRequestInterface $request, string $kind): ?array
    {
        $ctx = $this->context($request, ApiTokenScope::MANAGE);
        if (isset($ctx['status'])) {
            return $ctx;
        }

        return $this->assets->uploadRefusal(
            $ctx['token']['account_id'],
            $ctx['board'],
            $kind,
            ['id' => $ctx['token']['created_by_user_id']],
        );
    }

    /**
     * Stores already-read image bytes. The caller ran assetRefusal() and checked
     * the byte limit.
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    public function storeAsset(ServerRequestInterface $request, string $kind, string $raw): array
    {
        $ctx = $this->context($request, ApiTokenScope::MANAGE);
        if (isset($ctx['status'])) {
            return $ctx;
        }
        $token  = $ctx['token'];
        $result = $this->assets->storeImage($token['account_id'], $ctx['board'], $kind, $raw);
        $this->recordWrite($token, (int) $ctx['board']['id'], 'asset_upload', $request, $result['status']);

        return $result;
    }

    /** @return array{status: int, body: array<string, mixed>} */
    public function removeAsset(ServerRequestInterface $request, string $kind): array
    {
        $ctx = $this->context($request, ApiTokenScope::MANAGE);
        if (isset($ctx['status'])) {
            return $ctx;
        }
        $token  = $ctx['token'];
        $result = $this->assets->removeKind($ctx['board'], $kind);
        $this->recordWrite($token, (int) $ctx['board']['id'], 'asset_delete', $request, $result['status']);

        return $result;
    }

    /**
     * MCP upload from base64. Refusal and the byte limit run before anything is
     * decoded, so an oversized payload costs no decode work.
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    public function uploadBase64(ServerRequestInterface $request, string $kind, mixed $encoded, mixed $contentType): array
    {
        $refusal = $this->assetRefusal($request, $kind);
        if ($refusal !== null) {
            return $refusal;
        }
        if (!is_string($encoded) || $encoded === '') {
            return $this->failure(422, 'invalid_upload', 'The image_base64 argument is required.');
        }
        if ($contentType !== null && !in_array($contentType, self::MCP_CONTENT_TYPES, true)) {
            return $this->failure(422, 'unsupported_format', 'Only PNG, JPEG or WebP images are accepted.');
        }

        $limit = $this->processor->maxUploadBytesFor($kind);
        // Base64 maps 3 bytes to 4 characters: reject an oversized string before decoding it.
        if (strlen($encoded) > intdiv($limit + 2, 3) * 4) {
            return $this->failure(422, 'file_too_large', 'The file exceeds the size limit.');
        }

        $raw = base64_decode($encoded, true);
        if ($raw === false) {
            return $this->failure(422, 'invalid_upload', 'The image_base64 argument is not valid base64.');
        }
        if (strlen($raw) > $limit) {
            return $this->failure(422, 'file_too_large', 'The file exceeds the size limit.');
        }

        return $this->storeAsset($request, $kind, $raw);
    }

    // -------------------------------------------------------------------------
    // REST wrappers
    // -------------------------------------------------------------------------
    public function getBranding(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $result = $this->view($request);

        return $this->json($response, $result['status'], $result['body']);
    }

    public function patchBranding(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $result = $this->update($request, $request->getParsedBody());

        return $this->json($response, $result['status'], $result['body']);
    }

    /** @param array<string, mixed> $args */
    public function uploadAsset(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $kind    = $this->kindArg($args);
        $refusal = $this->assetRefusal($request, $kind);
        if ($refusal !== null) {
            return $this->json($response, $refusal['status'], $refusal['body']);
        }

        $limit = $this->processor->maxUploadBytesFor($kind);
        $file  = $request->getUploadedFiles()['image'] ?? null;
        if (!$file instanceof UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) {
            $failure = $this->failure(422, 'invalid_upload', 'No valid file was uploaded.');

            return $this->json($response, $failure['status'], $failure['body']);
        }
        if ($file->getSize() !== null && $file->getSize() > $limit) {
            $failure = $this->failure(422, 'file_too_large', 'The file exceeds the size limit.');

            return $this->json($response, $failure['status'], $failure['body']);
        }

        $raw = (string) $file->getStream();
        if (strlen($raw) > $limit) {
            $failure = $this->failure(422, 'file_too_large', 'The file exceeds the size limit.');

            return $this->json($response, $failure['status'], $failure['body']);
        }

        $result = $this->storeAsset($request, $kind, $raw);

        return $this->json($response, $result['status'], $result['body']);
    }

    /** @param array<string, mixed> $args */
    public function deleteAsset(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $result = $this->removeAsset($request, $this->kindArg($args));

        return $this->json($response, $result['status'], $result['body']);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * The grant covers $required and the board belongs to the token's account.
     * Read accepts any grant; manage needs the (effective) manage grant.
     *
     * @return array{token: array{account_id: int, scope: string, token_id: int, created_by_user_id: int, label: string}, board: array<string, mixed>}|array{status: int, body: array<string, mixed>}
     */
    private function context(ServerRequestInterface $request, string $required): array
    {
        /** @var array{account_id: int, scope: string, token_id: int, created_by_user_id: int, label: string}|null $token */
        $token = $request->getAttribute(ApiTokenAuthMiddleware::ATTR_TOKEN);
        if (!is_array($token)) {
            return $this->failure(401, 'unauthorized', 'A valid bearer token is required.');
        }

        $granted = $required === ApiTokenScope::MANAGE
            ? ApiTokenScope::allowsManage($token['scope'])
            : in_array($token['scope'], ApiTokenScope::ALL, true);
        if (!$granted) {
            return $this->failure(403, 'insufficient_scope', 'This action requires a token with the manage grant for this board.');
        }

        $accountId = (int) $request->getAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID);
        $boardId   = (int) $request->getAttribute(ApiTokenAuthMiddleware::ATTR_BOARD_ID);
        if ($accountId !== $token['account_id']) {
            return $this->failure(404, 'not_found', 'Board not found.'); // defense in depth: both come from the same grant
        }

        $board = $this->boardRepo->findByIdForAccount($boardId, $accountId);
        if ($board === null) {
            return $this->failure(404, 'not_found', 'Board not found.');
        }

        return ['token' => $token, 'board' => $board];
    }

    /** @param array{account_id: int, created_by_user_id: int} $token */
    private function planFor(array $token): ResolvedPlan
    {
        return $this->plans->forAccount($token['account_id'], ['id' => $token['created_by_user_id']]);
    }

    /**
     * The body merged over the stored sanitized values of the basic branding
     * fields, so a PATCH that omits them keeps them (see update()).
     *
     * @param array<string, mixed> $board
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function withStoredBaseline(array $board, ResolvedPlan $plan, array $body): array
    {
        $view = $this->branding->describe($board, $plan);

        return array_replace([
            'primary_color'   => $view['primary_color'] ?? '',
            'secondary_color' => $view['secondary_color'] ?? '',
            'logo_url'        => $view['logo_url'] ?? '',
            'intro'           => $view['intro'] ?? '',
            'hide_badge'      => $view['hide_badge'],
        ], $body);
    }

    /**
     * Same shape as BoardBrandingAction::errorResponse().
     *
     * @param array<string, string> $fields
     * @param list<string> $locked
     * @return array<string, mixed>
     */
    private function validationError(array $fields, array $locked): array
    {
        $onlyLocked = $locked !== [] && count($locked) === count($fields);
        $error      = [
            'key'     => $onlyLocked ? 'plan_locked_field' : 'validation_error',
            'message' => 'Validation failed.',
            'fields'  => $fields,
        ];
        if ($locked !== []) {
            $error['locked_fields'] = $locked;
        }

        return $error;
    }

    /** @param array{token_id: int} $token */
    private function recordWrite(array $token, int $boardId, string $action, ServerRequestInterface $request, int $status): void
    {
        $this->audit->log('api_token.write', [
            'action'   => $action,
            'board_id' => $boardId,
            'token_id' => $token['token_id'],
            'via'      => $request->getAttribute(ApiIdeaWriteAction::ATTR_VIA) === 'mcp' ? 'mcp' : 'api_token',
            'status'   => $status,
        ]);
    }

    /** @param array<string, mixed> $args */
    private function kindArg(array $args): string
    {
        return is_string($args['kind'] ?? null) ? $args['kind'] : '';
    }

    /** @return array{status: int, body: array<string, mixed>} */
    private function failure(int $status, string $key, string $message): array
    {
        return ['status' => $status, 'body' => ['error' => ['key' => $key, 'message' => $message]]];
    }

    /** @param array<string, mixed> $payload */
    private function json(ResponseInterface $response, int $status, array $payload): ResponseInterface
    {
        $response->getBody()->write((string) json_encode($payload));

        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
