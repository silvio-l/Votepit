<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Domain\BoardPhases;
use Votepit\Http\Middleware\AccountContextMiddleware;
use Votepit\Http\Middleware\AuthNMiddleware;
use Votepit\Http\Support\FrozenBoardGuard;
use Votepit\Http\Support\JsonError;
use Votepit\Logging\AuditLogger;
use Votepit\Persistence\BlockRepository;
use Votepit\Persistence\BoardRepository;
use Votepit\Persistence\BoardStatusRepository;
use Votepit\Persistence\IdeaRepository;
use Votepit\Persistence\VoteRepository;
use Votepit\Security\AnonymousVoteCookie;
use Votepit\Security\AnonymousVoterHasher;
use Votepit\Security\EncryptionService;
use Votepit\Security\Turnstile\TurnstileGuard;
use Votepit\Support\Clock;

/**
 * POST /{board}/ideas/{id}/vote — cast / change / retract a vote.
 *
 * AuthZ: anon() at route level (AppFactory) — the actual authorization
 * decision is board-dependent and lives HERE: an authenticated user is
 * always allowed; an anonymous requester is allowed ONLY when
 * board.anonymous_voting_enabled is true (ADR 0004), otherwise 401 —
 * IDENTICAL to this route's behaviour before anonymous voting existed
 * (every board defaults to the flag OFF).
 * CSRF: globally enforced (CsrfMiddleware on the POST path).
 * BlockCheck: global (blocked user → 403, before the action runs) — for
 * the authenticated path only; see the "anonymous voting" doc block below
 * for why anonymous voters have no equivalent block-by-identity yet.
 * RateLimit: perAction('idea:vote') in AppFactory, keyed by user ID when
 * authenticated, by client IP when anonymous (previously anon requests were
 * unreachable here at all, so no identity was needed).
 *
 * Board-scoping is structural: the idea is loaded board-scoped via findInBoard() —
 * unknown slug or idea outside the board → 404 (no cross-board leak,
 * no vote row is created).
 *
 * Input `value` ∈ {up,down} (resp. +1/-1); other values → 422, no mutation.
 *
 * Always responds with JSON { score, my_vote, up_count, down_count }, status 200.
 * Goes through the same middleware pipeline (AuthZ, CSRF, BlockCheck, RateLimit).
 *
 * Board-scoped user block: a thin inline guard (no
 * central middleware), the board is already loaded at this point.
 * Runs additively to the account-wide check (BlockCheckMiddleware,
 * already run before the action).
 *
 * Anti-automation challenge (OWASP/BSI audit remediation issue 20):
 * TurnstileGuard — invisible to ordinary users, only engaged once the
 * requester's IP has repeatedly tripped the existing rate limits. See
 * TurnstileGuard's class doc.
 *
 * Anonymous voting (ADR 0004 — "vote without an account"):
 *   - Only reachable when board.anonymous_voting_enabled is true (owner
 *     opt-in, default off — see BoardRepository::updateAnonymousVotingEnabled()).
 *   - Identity is a random token in a first-party, httpOnly, SameSite=Lax
 *     cookie (AnonymousVoteCookie) — only its HMAC (AnonymousVoterHasher::
 *     hashToken()) is ever persisted (votes.voter_token_hash), never the
 *     raw value. No device fingerprinting.
 *   - A brand-new cookie (no prior valid token on this browser) MUST solve
 *     a Turnstile challenge before its first vote — unconditionally, not
 *     only after repeatedly tripping rate limits like the existing
 *     TurnstileGuard trigger above. Skipped (fail-open) only when
 *     Turnstile isn't configured at all (self-host default) — same
 *     fail-secure-by-absence philosophy as TurnstileGuard itself; forcing
 *     an unconfigured challenge would make anonymous voting permanently
 *     unusable on such an install.
 *   - Hard rate-limited (429) via the same idea:vote bucket, keyed by
 *     client IP for anonymous requests (see AppFactory wiring).
 *   - Every anonymous cast, and every rejected Turnstile/rate-limit
 *     attempt, is audit-logged (masked — the token hash passes through
 *     AuditLogger::mask() as-is via the `_hmac` suffix convention, never
 *     the raw cookie value) so moderators can review abuse patterns.
 *   - An optional notification email (`notify_email` field) may be left —
 *     it is USED: IdeaStatusAction mails it on an idea-status change (same
 *     content as registered voters get), which is why it is reversibly
 *     ENCRYPTED at rest (EncryptionService, context 'anon_vote_email' —
 *     domain-separated from every other at-rest secret under the same
 *     app_key, and from the ADR-0002 login-email HMAC, so the ciphertext
 *     can never be linked to a platform account without the app_key
 *     itself) rather than irreversibly hashed like the token — a hash
 *     could never be used to actually send the mail it promises. NEVER
 *     required for the vote to succeed.
 *   - Double-vote prevention: once this browser's anon-voter cookie has
 *     been seen alongside an authenticated vote (voteAuthenticated() links
 *     it to that user_id, minting the cookie then if it didn't exist yet),
 *     every later anonymous request from the SAME cookie casts under that
 *     user_id instead of creating a second, independent voter_token_hash
 *     identity — one person can no longer vote twice by logging out and
 *     voting anonymously (or the reverse order). Deliberately global, not
 *     board-scoped, same as the cookie/token itself; a cookie already
 *     linked to one account is never silently reassigned to another (first
 *     link wins) — see VoteRepository::linkVoterIdentity().
 *   - Scope decision, not a half-built path: moderators cannot yet block
 *     an individual anonymous voter (only a Turnstile+rate-limit+audit-log
 *     triad, decision "2b+c"). Blocking-by-cookie is a reasonable future
 *     extension but adds its own schema churn on the security-sensitive
 *     blocked_users table; trivially bypassed by clearing cookies anyway
 *     (disclosed limitation, ADR 0004, same as every competitor's
 *     anonymous-voting implementation).
 */
final readonly class VoteAction
{
    /** Turnstile response-token form field name — see IdeaCreateAction::TURNSTILE_FIELD. */
    public const TURNSTILE_FIELD = 'turnstile_token';

    /** Optional anonymous-voter notification email form field name. */
    public const NOTIFY_EMAIL_FIELD = 'notify_email';

    public function __construct(
        private BoardRepository $boardRepo,
        private IdeaRepository $ideaRepo,
        private VoteRepository $voteRepo,
        private AuditLogger $audit,
        private BlockRepository $blockRepo,
        private TurnstileGuard $turnstileGuard,
        private AnonymousVoteCookie $anonCookie,
        private AnonymousVoterHasher $voterHasher,
        private EncryptionService $emailEncryption,
        private BoardStatusRepository $statusRepo,
        private Clock $clock,
    ) {}

    /**
     * @param array<string, mixed> $args
     */
    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $slug      = is_string($args['board'] ?? null) ? $args['board'] : '';
        $accountId = (int) $request->getAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID);
        $board     = $this->boardRepo->findBySlugForAccount($slug, $accountId);
        if (!is_array($board)) {
            return JsonError::respond($response, 404, 'not_found', 'Board not found.');
        }

        if (FrozenBoardGuard::isFrozen($board)) {
            return FrozenBoardGuard::reject($response);
        }

        $boardId = (int) $board['id'];
        $ideaId  = (int) ($args['id'] ?? 0);
        $idea    = $this->ideaRepo->findInBoard($boardId, $ideaId);
        if (!is_array($idea)) {
            return JsonError::respond($response, 404, 'not_found', 'Idea not found.');
        }

        // Timer phase (broaden-use-cases issue 04, migration 0082): an
        // optional voting window. Effective rule = window AND the status
        // flag below. Applies to signed-in and anonymous voters alike (incl.
        // retracting), existing votes stay untouched. Pure computation over
        // the already-loaded board row — no extra query.
        $votingRejection = BoardPhases::fromBoard($board, $this->clock->now())->votingRejection();
        if ($votingRejection !== null) {
            return JsonError::respond($response, 423, $votingRejection, $votingRejection === 'voting_closed'
                ? 'Voting on this board has closed.'
                : 'Voting on this board has not opened yet.');
        }

        // Status-driven phase (broaden-use-cases issue 03): a status with
        // allow_votes = false closes voting; existing votes stay untouched.
        // Default boards never pay a query here (registry from the board row).
        if ($this->statusRepo->registryFor($board)->find((string) $idea['status_key'])?->allowVotes === false) {
            return JsonError::respond($response, 423, 'status_closed_for_votes', 'Voting is closed for ideas in this status.');
        }

        $value = $this->parseValue($request);
        if ($value === null) {
            return JsonError::respond($response, 422, 'invalid_vote_value', 'Invalid vote value.');
        }

        // Anti-automation challenge (OWASP/BSI audit remediation issue 20)
        // — see TurnstileGuard's class doc. Only engaged once this IP has
        // repeatedly tripped the existing rate limits. Applies uniformly to
        // authenticated AND anonymous requests alike.
        if ($this->turnstileGuard->requiresChallengeFor($request) && !$this->turnstileGuard->verifyFor($request, $this->turnstileTokenFrom($request))) {
            return JsonError::respond($response, 422, 'turnstile_required', 'Please complete the verification challenge to continue.', [
                'site_key' => $this->turnstileGuard->siteKey(),
            ]);
        }

        /** @var array<string, mixed>|null $user */
        $user = $request->getAttribute(AuthNMiddleware::ATTR_USER);

        return is_array($user)
            ? $this->voteAuthenticated($request, $response, $board, $boardId, $ideaId, $accountId, (int) ($user['id'] ?? 0), $value)
            : $this->voteAnonymous($request, $response, $board, $boardId, $ideaId, $accountId, $value);
    }

    /**
     * @param array<string, mixed> $board
     */
    private function voteAuthenticated(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $board,
        int $boardId,
        int $ideaId,
        int $accountId,
        int $userId,
        int $value,
    ): ResponseInterface {
        if ($this->blockRepo->isBlocked($accountId, $userId, $boardId)) {
            return JsonError::respond($response, 403, 'blocked', 'You are blocked from this board.');
        }

        // Double-vote-prevention follow-up (found via live testing: log in
        // and vote, log out, vote again anonymously on the same idea
        // previously counted twice — ADR 0004). Only on boards where the
        // anonymous path is even reachable: links this browser's anon-voter
        // cookie (minting one now if absent) to this user, so a LATER
        // anonymous request from the same cookie casts under this user_id
        // instead of creating a second, independent vote identity. If this
        // cookie already cast a stray anonymous vote on THIS idea before
        // being linked (the reverse order — vote anonymously, then log in
        // and vote again), fold it away in the same transaction as the
        // cast below rather than leaving a second, orphaned row.
        $mergeTokenHash = null;
        $issueCookie    = null;
        if ((bool) ($board['anonymous_voting_enabled'] ?? false)) {
            $rawToken   = $this->anonCookie->read($request);
            $isNewToken = $rawToken === null;
            if ($isNewToken) {
                $rawToken = AnonymousVoterHasher::generateToken();
            }
            $tokenHash      = $this->voterHasher->hashToken($rawToken);
            $mergeTokenHash = $tokenHash;
            $this->voteRepo->linkVoterIdentity($tokenHash, $userId);
            if ($isNewToken) {
                $issueCookie = $rawToken;
            }
        }

        $result   = $this->voteRepo->cast($boardId, $ideaId, $userId, $value, $mergeTokenHash);
        $response = $this->jsonResult($response, $result);

        // Masked audit: board/idea ID, direction, result — no PII.
        $this->audit->log('vote.cast', [
            'board_id'  => $boardId,
            'idea_id'   => $ideaId,
            'direction' => $value > 0 ? 'up' : 'down',
            'result'    => $result['my_vote'],
        ]);

        return $issueCookie !== null ? $this->anonCookie->issue($response, $issueCookie) : $response;
    }

    /**
     * @param array<string, mixed> $board
     */
    private function voteAnonymous(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $board,
        int $boardId,
        int $ideaId,
        int $accountId,
        int $value,
    ): ResponseInterface {
        if (!(bool) ($board['anonymous_voting_enabled'] ?? false)) {
            return JsonError::respond($response, 401, 'login_required', 'Please sign in to vote.');
        }

        $rawToken   = $this->anonCookie->read($request);
        $isNewToken = $rawToken === null;
        if ($isNewToken) {
            $rawToken = AnonymousVoterHasher::generateToken();
        }

        // Double-vote-prevention follow-up (ADR 0004): this browser's
        // cookie was already linked to a real account on a previous
        // authenticated vote (voteAuthenticated()) — cast as THAT user
        // instead of creating a second, independent voter_token_hash
        // identity. Structurally closes the "vote while logged in, log
        // out, vote again anonymously" double-count found via live testing.
        if (!$isNewToken) {
            $linkedUserId = $this->voteRepo->findLinkedUserId($this->voterHasher->hashToken($rawToken));
            if ($linkedUserId !== null) {
                return $this->voteAuthenticated($request, $response, $board, $boardId, $ideaId, $accountId, $linkedUserId, $value);
            }
        }

        // Forced challenge on first-ever token issuance — see class doc.
        // Fail-open only when Turnstile isn't configured at all.
        if ($isNewToken && $this->turnstileGuard->isConfigured() && !$this->turnstileGuard->verifyFor($request, $this->turnstileTokenFrom($request))) {
            $this->audit->log('vote.anon_turnstile_required', ['board_id' => $boardId, 'idea_id' => $ideaId]);
            return JsonError::respond($response, 422, 'turnstile_required', 'Please complete the verification challenge to continue.', [
                'site_key' => $this->turnstileGuard->siteKey(),
            ]);
        }

        $rawEmail = trim($this->bodyField($request, self::NOTIFY_EMAIL_FIELD));
        if ($rawEmail !== '' && filter_var($rawEmail, FILTER_VALIDATE_EMAIL) === false) {
            return JsonError::respond($response, 422, 'invalid_email', 'Please enter a valid email address.');
        }
        $emailEnc = $rawEmail !== '' ? $this->emailEncryption->encrypt($rawEmail) : null;

        $tokenHash = $this->voterHasher->hashToken($rawToken);
        $result    = $this->voteRepo->castAnonymous($boardId, $ideaId, $tokenHash, $value, $emailEnc);

        // Masked audit: board/idea ID, direction, result, and the voter
        // token's HMAC (an `_hmac`-suffixed field is already pseudonymized
        // — AuditLogger::mask() passes it through as-is, never the raw
        // cookie value) — lets moderators spot one identity voting across
        // many ideas/boards without ever learning who it is.
        $this->audit->log('vote.cast_anonymous', [
            'board_id'         => $boardId,
            'idea_id'          => $ideaId,
            'direction'        => $value > 0 ? 'up' : 'down',
            'result'           => $result['my_vote'],
            'voter_token_hmac' => $tokenHash,
        ]);

        $result = $this->jsonResult($response, $result);

        return $isNewToken ? $this->anonCookie->issue($result, $rawToken) : $result;
    }

    private function turnstileTokenFrom(ServerRequestInterface $request): string
    {
        return $this->bodyField($request, self::TURNSTILE_FIELD);
    }

    private function bodyField(ServerRequestInterface $request, string $field): string
    {
        $parsed = $request->getParsedBody();

        return is_array($parsed) ? (string) ($parsed[$field] ?? '') : '';
    }

    /** @param array{my_vote: 'up'|'down'|'none', score: int, up_count: int, down_count: int} $result */
    private function jsonResult(ResponseInterface $response, array $result): ResponseInterface
    {
        $json = (string) json_encode([
            'score'      => $result['score'],
            'my_vote'    => $result['my_vote'],
            'up_count'   => $result['up_count'],
            'down_count' => $result['down_count'],
        ]);
        $response->getBody()->write($json);

        return $response
            ->withStatus(200)
            ->withHeader('Content-Type', 'application/json');
    }

    /**
     * Strictly validates the `value` field: only {up,+1,1} → 1, {down,-1} → -1.
     * Anything else → null (action responds with 422, no mutation).
     */
    private function parseValue(ServerRequestInterface $request): ?int
    {
        $raw = $this->bodyField($request, 'value');

        return match ($raw) {
            'up', '1', '+1' => 1,
            'down', '-1'    => -1,
            default         => null,
        };
    }
}
