<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Doctrine\DBAL\Exception as DbalException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Votepit\Config;
use Votepit\Domain\BoardPhases;
use Votepit\Domain\ContentModerationService;
use Votepit\Domain\Premoderation;
use Votepit\Domain\PremoderationNotifier;
use Votepit\Domain\TitleNormalizer;
use Votepit\Domain\UrlInText;
use Votepit\Http\Middleware\AccountContextMiddleware;
use Votepit\Http\Middleware\AuthZMiddleware;
use Votepit\Http\Support\AnonymousSubmissionGate;
use Votepit\Http\Support\FrozenBoardGuard;
use Votepit\Http\Support\IdeaInputChecks;
use Votepit\Http\Support\IdeaTagInput;
use Votepit\Http\Support\JsonError;
use Votepit\Logging\AuditLogger;
use Votepit\Logging\AuthzDenialLogger;
use Votepit\Persistence\BoardRepository;
use Votepit\Persistence\IdeaRepository;
use Votepit\Persistence\ModerationConfigRepository;
use Votepit\Persistence\TagRepository;
use Votepit\Persistence\UserRepository;
use Votepit\Security\AnonymousAuthorCookie;
use Votepit\Security\AnonymousAuthorHasher;
use Votepit\Security\ClientIp;
use Votepit\Security\IpRateKey;
use Votepit\Security\RateLimiter;
use Votepit\Security\TimeTrapService;
use Votepit\Security\Turnstile\TurnstileGuard;
use Votepit\Support\Clock;
use Votepit\Webhook\WebhookDispatcher;

/**
 * POST /{board}/ideas without a session — guest idea submission
 * (broaden-use-cases issue 06, ADR 0006 §3). Called by IdeaCreateAction
 * before its signed-in path, never mounted on its own.
 *
 * Order of checks, each one refusing fail-secure:
 *   1. public board (findPublicBySlugForAccount, viewerIsMember=false):
 *      private / unknown / unconfirmed / locked → 404; frozen → 423;
 *      submission window (no exemption for guests) → 423
 *   2. switch off or plan does not allow it → the unchanged 401 of
 *      AuthZMiddleware::user() (reused, not rebuilt — same body, same
 *      denial log entry)
 *   3. unresolvable client IP → 422 rejected
 *   4. IP bucket (IPv6 /64-grouped; 429 records Turnstile suspicion), then
 *      board bucket → 429 rate_limited
 *   5. Turnstile on EVERY guest submission, fail-closed, widget action
 *      `anon_idea_submit` → 422 captcha_unavailable / captcha_failed
 *   6. device-token bucket (hash of an existing cookie) → 429
 *   7. honeypot / time trap → 422 rejected (silent, as for members)
 *   8. lengths (shared IdeaInputChecks) → 422 validation_error
 *   9. no URLs (Domain\UrlInText) → 422 url_not_allowed
 *  10. word filter, forced even if the board switched it off → 422
 *  11. at most QUEUE_CAP pending guest ideas per board → 429 queue_full
 *  12. insert under the guest placeholder + account-bound device hash;
 *      pending per the board's pre-moderation; cookie issued if absent.
 *
 * Rejections from step 4 on are audited as `idea.anon_rejected` with a
 * reason code only (no content, no matched words, no raw token).
 */
final readonly class AnonymousIdeaSubmission
{
    public const TURNSTILE_ACTION = 'anon_idea_submit';

    /** Pending guest ideas per board before new ones are refused (ADR 0006 §5). */
    public const QUEUE_CAP = 50;

    public function __construct(
        private BoardRepository $boardRepo,
        private IdeaRepository $ideaRepo,
        private UserRepository $userRepo,
        private TitleNormalizer $normalizer,
        private AuditLogger $audit,
        private ContentModerationService $moderation,
        private ModerationConfigRepository $moderationConfigRepo,
        private TimeTrapService $timeTrap,
        private TurnstileGuard $turnstileGuard,
        private RateLimiter $limiter,
        private Config $config,
        private AnonymousSubmissionGate $gate,
        private AnonymousAuthorCookie $cookie,
        private AnonymousAuthorHasher $hasher,
        private ResponseFactoryInterface $responseFactory,
        private AuthzDenialLogger $denialLogger,
        private PremoderationNotifier $premoderationNotifier,
        private ?WebhookDispatcher $webhookDispatcher,
        private Clock $clock,
        private ?TagRepository $tagRepo = null,
    ) {}

    /** @param array<string, mixed> $args */
    public function handle(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $slug      = is_string($args['board'] ?? null) ? $args['board'] : '';
        $accountId = (int) $request->getAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID);

        // 1. Only boards a stranger may see at all.
        $board = $this->boardRepo->findPublicBySlugForAccount($slug, $accountId);
        if (!is_array($board)) {
            return JsonError::respond($response, 404, 'not_found', 'Board not found.');
        }

        // 2. Not open to guests → exactly what an anonymous POST got before.
        if (!$this->gate->switchedOn($board, $accountId)) {
            return self::unauthenticated($request, $this->responseFactory, $this->denialLogger);
        }

        if (FrozenBoardGuard::isFrozen($board)) {
            return FrozenBoardGuard::reject($response);
        }

        $submissionRejection = BoardPhases::fromBoard($board, $this->clock->now())->submissionRejection();
        if ($submissionRejection !== null) {
            return JsonError::respond($response, 423, $submissionRejection, $submissionRejection === 'submissions_closed'
                ? 'Submitting ideas on this board has closed.'
                : 'Submitting ideas on this board has not opened yet.');
        }

        $boardId = (int) $board['id'];

        // 3. No client identity → no limits possible → refuse.
        $ip    = ClientIp::resolve($request, $this->config->trustCloudflareIp);
        $ipKey = IpRateKey::of($ip);
        if ($ip === null || $ipKey === null) {
            return JsonError::respond($response, 422, 'rejected', 'The request was rejected.');
        }

        // 4. Cheap limits first, so a flood never reaches siteverify.
        $ipLimit = $this->config->mandatoryRateLimit('idea:submit:anon:ip', 10, 3600);
        if (!$this->hit('idea:submit:anon:ip:' . $ipKey, $ipLimit)) {
            $this->turnstileGuard->recordSuspicion($ip);

            return $this->rateLimited($response, $boardId, $ipLimit['window']);
        }
        $boardLimit = $this->config->mandatoryRateLimit('idea:submit:anon:board', 30, 3600);
        if (!$this->hit('idea:submit:anon:board:' . $boardId, $boardLimit)) {
            return $this->rateLimited($response, $boardId, $boardLimit['window']);
        }

        $parsed = $request->getParsedBody();
        $parsed = is_array($parsed) ? $parsed : [];

        // 5. Turnstile, always, fail-closed.
        if (!$this->turnstileGuard->isConfigured()) {
            return $this->reject($response, $boardId, 422, 'captcha_unavailable', 'Submitting without an account is not available right now.');
        }
        $turnstileToken = is_string($parsed[IdeaCreateAction::TURNSTILE_FIELD] ?? null) ? $parsed[IdeaCreateAction::TURNSTILE_FIELD] : '';
        if (!$this->turnstileGuard->verify($turnstileToken, $ip, self::TURNSTILE_ACTION)) {
            return $this->reject($response, $boardId, 422, 'captcha_failed', 'The verification failed. Please try again.', ['site_key' => $this->turnstileGuard->siteKey()]);
        }

        // 6. Per-device limit. A request without the cookie gets the token it
        //    will be issued (taken over from vp_anon_voter, or fresh), so the
        //    first submission already counts for that device.
        $rawToken    = $this->cookie->read($request);
        $issueCookie = $rawToken === null;
        $rawToken  ??= AnonymousAuthorCookie::initialToken($request);
        $tokenHash   = $this->hasher->hashToken($accountId, $rawToken);
        $tokenLimit  = $this->config->mandatoryRateLimit('idea:submit:anon:token', 3, 3600);
        if (!$this->hit('idea:submit:anon:token:' . $tokenHash, $tokenLimit)) {
            return $this->rateLimited($response, $boardId, $tokenLimit['window']);
        }

        // 7. Bot traps — silent, exactly like the member path.
        $honeypot = (string) ($parsed[IdeaCreateAction::HONEYPOT_FIELD] ?? '');
        $timeTrap = (string) ($parsed[IdeaCreateAction::TIME_TRAP_FIELD] ?? '');
        if ($honeypot !== '' || !$this->timeTrap->verify($timeTrap)) {
            return JsonError::respond($response, 422, 'rejected', 'The request was rejected.');
        }

        // 8. Lengths.
        $title  = trim(is_scalar($parsed['title'] ?? null) ? (string) $parsed['title'] : '');
        $body   = trim(is_scalar($parsed['body'] ?? null) ? (string) $parsed['body'] : '');
        $fields = IdeaInputChecks::fieldErrors($title, $body);
        if ($fields !== []) {
            return IdeaInputChecks::validationError($response, $fields, $title, $body);
        }

        // 9. No links from guests.
        if (UrlInText::containsUrl($title) || UrlInText::containsUrl($body)) {
            return $this->reject($response, $boardId, 422, 'url_not_allowed', 'Links are not allowed when submitting without an account.');
        }

        // 10. Word filter, forced.
        if (!IdeaInputChecks::wordFilter($this->moderation, $this->moderationConfigRepo, $boardId, true, $title, $body)['clean']) {
            $this->auditRejection($boardId, 'moderation_blocked');

            return IdeaInputChecks::moderationBlocked($response);
        }

        // 10b. Optional tags (P-34): the same existing-board-tag rules as members.
        $tagInput = IdeaTagInput::resolve($this->tagRepo, $boardId, $parsed['tag_ids'] ?? null);
        if ($tagInput['error'] !== null) {
            return $this->reject($response, $boardId, 422, $tagInput['error'], IdeaTagInput::message($tagInput['error']));
        }

        // 11. Queue cap — members are never counted nor blocked.
        $guestId = $this->userRepo->findOrCreateGuestPlaceholder();
        if ($this->ideaRepo->countPendingGuestIdeas($boardId, $guestId) >= self::QUEUE_CAP) {
            return $this->reject($response, $boardId, 429, 'queue_full', 'Many suggestions are waiting for review right now. Please try again later.');
        }

        // 12. Store.
        $mode    = Premoderation::normalizeMode($board['premoderation'] ?? null);
        $pending = Premoderation::requiresApproval($mode, null, isGuest: true);
        $state   = $pending ? Premoderation::STATE_PENDING : Premoderation::STATE_PUBLISHED;

        $ideaId = $this->ideaRepo->create($boardId, $guestId, $title, $this->normalizer->normalize($title), $body, $state, $tokenHash);
        foreach ($tagInput['ids'] as $tagId) {
            $this->tagRepo?->assignToIdea($ideaId, $tagId);
        }

        $this->audit->log('idea.created', [
            'board_id'          => $boardId,
            'idea_id'           => $ideaId,
            'origin'            => 'guest',
            'moderation_state'  => $state,
            'author_token_hmac' => $tokenHash,
        ]);

        if ($pending) {
            $boardName = is_string($board['name'] ?? null) ? $board['name'] : $slug;
            $this->premoderationNotifier->notifyModerators($accountId, $slug, $boardName, $guestId);
        } else {
            // Same board-visible payload as a member idea — no author field.
            $this->webhookDispatcher?->dispatchAsync($boardId, $slug, 'idea.created', [
                'idea_id' => $ideaId,
                'title'   => $title,
                'status'  => 'open',
            ]);
        }

        $response->getBody()->write((string) json_encode(['ok' => true, 'id' => $ideaId, 'moderation_state' => $state]));
        $response = $response->withStatus(201)->withHeader('Content-Type', 'application/json');

        return $issueCookie ? $this->cookie->issue($response, $rawToken) : $response;
    }

    /**
     * The 401 an anonymous caller got from AuthZMiddleware::user() before
     * issue 06 — produced by that very middleware (same body, headers and
     * AuthzDenialLogger entry), so "switched off" stays byte-identical.
     */
    public static function unauthenticated(ServerRequestInterface $request, ResponseFactoryInterface $responseFactory, AuthzDenialLogger $denialLogger): ResponseInterface
    {
        return AuthZMiddleware::user($responseFactory, $denialLogger)->process($request, new class () implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new \LogicException('AuthZMiddleware::user() never passes a request without a user.');
            }
        });
    }

    /** @param array{limit: int, window: int} $limit */
    private function hit(string $bucket, array $limit): bool
    {
        try {
            return $this->limiter->hit($bucket, $limit['limit'], $limit['window']);
        } catch (DbalException) {
            // Repo convention: rate limits are an availability gate and fail
            // open on DB errors; Turnstile (fail-closed) and the insert
            // itself still need a working backend.
            return true;
        }
    }

    private function rateLimited(ResponseInterface $response, int $boardId, int $window): ResponseInterface
    {
        return $this->reject($response, $boardId, 429, 'rate_limited', 'Too many requests. Please try again shortly.')
            ->withHeader('Retry-After', (string) $window);
    }

    /** @param array<string, mixed> $extra */
    private function reject(ResponseInterface $response, int $boardId, int $status, string $key, string $message, array $extra = []): ResponseInterface
    {
        $this->auditRejection($boardId, $key);

        return JsonError::respond($response, $status, $key, $message, $extra);
    }

    private function auditRejection(int $boardId, string $reason): void
    {
        $this->audit->log('idea.anon_rejected', ['board_id' => $boardId, 'reason' => $reason]);
    }
}
