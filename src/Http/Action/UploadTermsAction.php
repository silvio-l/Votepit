<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Domain\UploadTerms;
use Votepit\Http\Middleware\AccountContextMiddleware;
use Votepit\Http\Middleware\AuthNMiddleware;
use Votepit\Http\Support\FrozenBoardGuard;
use Votepit\Logging\AuditLogger;
use Votepit\Persistence\BoardRepository;
use Votepit\Persistence\UploadTermsConsentRepository;

/**
 * POST /admin/boards/{slug}/assets/terms/accept — the owner/admin accepts the
 * upload clause (AGB clause 19, version $termsVersion) once per account.
 *
 * Body `lang` (optional): the UI language the clause was shown in; stored as
 * proof when it is one of Votepit\Domain\UploadTerms::UI_LANGUAGES, else NULL.
 *
 * AuthZ accountAdmin (owner/admin, set on the route). The board in the URL
 * resolves account-scoped first (404 for a foreign board), a frozen board
 * answers 423 like every other board write. Idempotent: a repeated accept
 * keeps the first row and answers 200 with the stored time.
 */
final readonly class UploadTermsAction
{
    public function __construct(
        private BoardRepository $boardRepo,
        private UploadTermsConsentRepository $consents,
        private AuditLogger $audit,
        private ?string $termsVersion,
    ) {}

    /** @param array<string, mixed> $args */
    public function accept(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        // No terms configured (Community default): nothing to accept, so the endpoint answers 404
        // and writes nothing. A 200 would imply a consent record that does not exist.
        $termsVersion = $this->termsVersion;
        if ($termsVersion === null) {
            return $this->error($response, 404, 'upload_terms_not_configured', 'Upload terms are not configured on this installation.');
        }

        $accountId = (int) $request->getAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID);
        $slug      = is_string($args['slug'] ?? null) ? $args['slug'] : '';
        $board     = $this->boardRepo->findBySlugForAccount($slug, $accountId);
        if (!is_array($board)) {
            return $this->error($response, 404, 'not_found', 'Board not found.');
        }
        if (FrozenBoardGuard::isFrozen($board)) {
            return FrozenBoardGuard::reject($response);
        }

        $user   = $request->getAttribute(AuthNMiddleware::ATTR_USER);
        $userId = is_array($user) ? (int) ($user['id'] ?? 0) : 0;
        if ($userId <= 0) {
            return $this->error($response, 401, 'unauthenticated', 'Sign in to continue.');
        }

        $parsed     = $request->getParsedBody();
        $uiLanguage = UploadTerms::normalizeLanguage(is_array($parsed) ? ($parsed['lang'] ?? null) : null);

        $boardId = (int) $board['id'];
        $this->consents->record($accountId, $boardId, $userId, $termsVersion, new \DateTimeImmutable(), $uiLanguage);
        $acceptedAt = $this->consents->acceptedAt($accountId, $termsVersion);

        $this->audit->log('board.upload_terms_accepted', ['board_id' => $boardId, 'terms_version' => $termsVersion]);

        return $this->json($response, 200, [
            'ok'            => true,
            'terms_version' => $termsVersion,
            'accepted_at'   => $acceptedAt,
        ]);
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
