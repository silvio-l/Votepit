<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Doctrine\DBAL\Exception as DbalException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Config;
use Votepit\Http\Middleware\AccountContextMiddleware;
use Votepit\Http\Support\AnonymousSubmissionGate;
use Votepit\Http\Support\FrozenBoardGuard;
use Votepit\Http\Support\JsonError;
use Votepit\Logging\AuditLogger;
use Votepit\Persistence\BoardRepository;
use Votepit\Persistence\IdeaRepository;
use Votepit\Persistence\UserRepository;
use Votepit\Security\AnonymousAuthorCookie;
use Votepit\Security\AnonymousAuthorHasher;
use Votepit\Security\RateLimiter;

/**
 * The submitting device's view of its guest ideas (ADR 0006 §7).
 *
 * GET  /{board}/ideas/guest-mine                 — this device's ideas on the board
 * POST /{board}/ideas/{id}/guest-withdraw        — hard-delete one of them while pending
 *
 * AuthZ anon at the route. Both answer 404 unless the board is public and
 * open to guests (flag ∧ plan; private/foreign/unknown boards are 404 too).
 * The device is identified only by HMAC(vp_anon_author, accountId) — a
 * cookie from another account hashes differently and matches nothing. Every
 * "not yours / not pending / unknown id / no cookie" answers the same 404,
 * so the route reveals nothing about ideas of other devices.
 */
final readonly class GuestIdeaAction
{
    /** Max rows of the device list (ADR 0006 §7). */
    public const MINE_LIMIT = 50;

    public function __construct(
        private BoardRepository $boardRepo,
        private IdeaRepository $ideaRepo,
        private UserRepository $userRepo,
        private AnonymousSubmissionGate $gate,
        private AnonymousAuthorCookie $cookie,
        private AnonymousAuthorHasher $hasher,
        private RateLimiter $limiter,
        private Config $config,
        private AuditLogger $audit,
    ) {}

    /** @param array<string, mixed> $args */
    public function mine(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $context = $this->context($request, $args);
        if ($context === null) {
            return JsonError::respond($response, 404, 'not_found', 'Board not found.');
        }
        [$board, $accountId] = $context;

        $raw     = $this->cookie->read($request);
        $guestId = $this->userRepo->guestPlaceholderId();
        $ideas   = $raw !== null && $guestId !== null
            ? $this->ideaRepo->listGuestIdeasForDevice((int) $board['id'], $guestId, $this->hasher->hashToken($accountId, $raw), self::MINE_LIMIT)
            : [];

        $response->getBody()->write((string) json_encode(['ideas' => $ideas]));

        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }

    /** @param array<string, mixed> $args */
    public function withdraw(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $context = $this->context($request, $args);
        if ($context === null) {
            return JsonError::respond($response, 404, 'not_found', 'Board not found.');
        }
        [$board, $accountId] = $context;

        if (FrozenBoardGuard::isFrozen($board)) {
            return FrozenBoardGuard::reject($response);
        }

        $raw = $this->cookie->read($request);
        if ($raw === null) {
            return JsonError::respond($response, 404, 'not_found', 'Idea not found.');
        }

        $hash  = $this->hasher->hashToken($accountId, $raw);
        $limit = $this->config->mandatoryRateLimit('idea:guest-withdraw:token', 10, 3600);
        try {
            $allowed = $this->limiter->hit('idea:guest-withdraw:token:' . $hash, $limit['limit'], $limit['window']);
        } catch (DbalException) {
            $allowed = true; // fail-open, repo convention for rate limits
        }
        if (!$allowed) {
            return JsonError::respond($response, 429, 'rate_limited', 'Too many requests. Please try again shortly.')
                ->withHeader('Retry-After', (string) $limit['window']);
        }

        $boardId = (int) $board['id'];
        $ideaId  = (int) ($args['id'] ?? 0);
        $guestId = $this->userRepo->guestPlaceholderId();
        if ($guestId === null || !$this->ideaRepo->deletePendingGuestIdea($boardId, $ideaId, $guestId, $hash)) {
            return JsonError::respond($response, 404, 'not_found', 'Idea not found.');
        }

        $this->audit->log('idea.guest_withdrawn', ['board_id' => $boardId, 'idea_id' => $ideaId]);

        $response->getBody()->write((string) json_encode(['ok' => true]));

        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }

    /**
     * @param array<string, mixed> $args
     * @return array{0: array<string, mixed>, 1: int}|null
     */
    private function context(ServerRequestInterface $request, array $args): ?array
    {
        $slug      = is_string($args['board'] ?? null) ? $args['board'] : '';
        $accountId = (int) $request->getAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID);
        $board     = $this->boardRepo->findPublicBySlugForAccount($slug, $accountId);

        if (!is_array($board) || !$this->gate->switchedOn($board, $accountId)) {
            return null;
        }

        return [$board, $accountId];
    }
}
