<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Http\Middleware\AccountContextMiddleware;
use Votepit\Http\Support\FrozenBoardGuard;
use Votepit\Http\Support\JsonError;
use Votepit\Logging\AuditLogger;
use Votepit\Persistence\BoardRepository;
use Votepit\Persistence\IdeaRepository;
use Votepit\Persistence\VoteRepository;
use Votepit\Security\AnonymousVoteCookie;
use Votepit\Security\AnonymousVoterHasher;
use Votepit\Security\EncryptionService;

/**
 * PATCH /{board}/ideas/{id}/vote/notify-email — add, change, or clear the
 * optional notification email on an anonymous voter's EXISTING vote (ADR
 * 0004 UX follow-up).
 *
 * Why this exists as its own endpoint rather than only ever being set
 * alongside POST .../vote: the email is a secondary, optional ask that
 * makes more sense revealed AFTER a vote is cast ("thanks — want updates?")
 * than as a field sitting in front of the vote buttons before any vote
 * exists. Splitting it out lets the UI ask for it at the right moment
 * without re-submitting the vote itself.
 *
 * Anonymous-only, mirroring VoteAction::voteAnonymous():
 *   - Requires board.anonymous_voting_enabled (ADR 0004) — otherwise 404,
 *     same fail-closed posture as the vote route's 401 (there is no
 *     anonymous identity concept to attach an email to on a board that
 *     never opted in).
 *   - Requires the voter's existing dedup cookie (AnonymousVoteCookie) AND
 *     an existing vote row for THIS idea under that identity — deliberately
 *     an UPDATE only (VoteRepository::updateAnonymousNotifyEmail(), never an
 *     upsert): an email with no vote behind it is PII collected for no
 *     purpose, the exact flaw already corrected once for the cast-time
 *     path. No cookie, or no matching vote → 404 (the client is expected to
 *     have cast a vote first — the UI only ever shows this after doing so).
 *   - No Turnstile/extra rate limit of its own: reachable only once the
 *     harder cast-time gates (forced challenge on a brand-new token, the
 *     idea:vote rate limit) have already been cleared to create the vote
 *     row this endpoint requires.
 *   - Every update is audit-logged (masked — voter identity only ever
 *     appears as its HMAC, never the raw cookie value or the email).
 *   - No separate authentication check: a registered voter simply has no
 *     voter_token_hash row to match (their vote is keyed by user_id), so
 *     this naturally 404s for them too — registered voters already get
 *     in-app notifications, nothing here to attach for them either way.
 */
final readonly class VoteNotifyEmailAction
{
    /** Notification email form field name — same name as VoteAction::NOTIFY_EMAIL_FIELD. */
    public const NOTIFY_EMAIL_FIELD = 'notify_email';

    public function __construct(
        private BoardRepository $boardRepo,
        private IdeaRepository $ideaRepo,
        private VoteRepository $voteRepo,
        private AuditLogger $audit,
        private AnonymousVoteCookie $anonCookie,
        private AnonymousVoterHasher $voterHasher,
        private EncryptionService $emailEncryption,
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
        if (!is_array($board) || !(bool) ($board['anonymous_voting_enabled'] ?? false)) {
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

        $rawToken = $this->anonCookie->read($request);
        if ($rawToken === null) {
            return JsonError::respond($response, 404, 'vote_required', 'Vote on this idea first.');
        }

        $rawEmail = trim($this->bodyField($request, self::NOTIFY_EMAIL_FIELD));
        if ($rawEmail !== '' && filter_var($rawEmail, FILTER_VALIDATE_EMAIL) === false) {
            return JsonError::respond($response, 422, 'invalid_email', 'Please enter a valid email address.');
        }
        $emailEnc = $rawEmail !== '' ? $this->emailEncryption->encrypt($rawEmail) : null;

        $tokenHash = $this->voterHasher->hashToken($rawToken);
        $updated   = $this->voteRepo->updateAnonymousNotifyEmail($ideaId, $tokenHash, $emailEnc);
        if (!$updated) {
            return JsonError::respond($response, 404, 'vote_required', 'Vote on this idea first.');
        }

        // Masked audit: board/idea ID and the voter token's HMAC only — never
        // the raw cookie value or the email itself.
        $this->audit->log('vote.notify_email_updated', [
            'board_id'         => $boardId,
            'idea_id'          => $ideaId,
            'voter_token_hmac' => $tokenHash,
            'cleared'          => $emailEnc === null,
        ]);

        $json = (string) json_encode(['ok' => true]);
        $response->getBody()->write($json);

        return $response
            ->withStatus(200)
            ->withHeader('Content-Type', 'application/json');
    }

    private function bodyField(ServerRequestInterface $request, string $field): string
    {
        $parsed = $request->getParsedBody();

        return is_array($parsed) ? (string) ($parsed[$field] ?? '') : '';
    }
}
