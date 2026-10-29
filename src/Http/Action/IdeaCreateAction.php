<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Domain\AccountRoles;
use Votepit\Domain\BoardPhases;
use Votepit\Domain\ContentModerationService;
use Votepit\Domain\Premoderation;
use Votepit\Domain\PremoderationNotifier;
use Votepit\Domain\TitleNormalizer;
use Votepit\Http\Middleware\AccountContextMiddleware;
use Votepit\Http\Middleware\AuthNMiddleware;
use Votepit\Http\Support\FrozenBoardGuard;
use Votepit\Http\Support\IdeaInputChecks;
use Votepit\Http\Support\IdeaTagInput;
use Votepit\Http\Support\JsonError;
use Votepit\Logging\AuditLogger;
use Votepit\Persistence\AccountMemberRepository;
use Votepit\Persistence\AccountMemberStandingRepository;
use Votepit\Persistence\BlockRepository;
use Votepit\Persistence\BoardRepository;
use Votepit\Persistence\IdeaRepository;
use Votepit\Persistence\ModerationConfigRepository;
use Votepit\Persistence\TagRepository;
use Votepit\Security\TimeTrapService;
use Votepit\Security\Turnstile\TurnstileGuard;
use Votepit\Support\Clock;
use Votepit\Support\SystemClock;
use Votepit\Webhook\WebhookDispatcher;

/**
 * POST /{board}/ideas — create an idea.
 *
 * AuthZ: anon at the route; signed-in callers take the path below, guests
 * go to AnonymousIdeaSubmission (see the end of this doc).
 * CSRF: globally enforced (CsrfMiddleware in the POST path).
 * RateLimit `idea:submit`: per-action rate limit (attached in AppFactory).
 *
 * Validation: title 3..200 characters, body min. 10 characters — Symfony
 * Validator. On error → 422 + the unified JSON error contract.
 * Success → 201 JSON `{"ok": true, "id": N}`.
 *
 * Moderation hard block, honeypot, time trap.
 *
 * Board-scoped user block — a thin inline guard directly here (no central
 * middleware), because the board is already loaded at this point. The
 * accountwide check additionally and unchangedly runs via
 * BlockCheckMiddleware before this action is even reached —
 * BlockRepository::isBlocked() with board_id set covers both levels
 * (account- and board-scoped) here in one query.
 *
 * Repeat-offender suspension (board-operator-compliance issue 10):
 * account_member_standing.state === 'suspended' rejects the submission
 * with a DEDICATED error key (`member_suspended`, 403) rather than
 * folding it into the generic `validation_error` shape — the frontend
 * needs to distinguish "fix your input" from "you currently cannot
 * submit at all" (PRD). Checked after the block guard (both are
 * account-standing gates, block first since it's already established
 * further up) and before any parsing/validation of the submitted content.
 *
 * Anti-automation challenge (OWASP/BSI audit remediation issue 20):
 * honeypot + time-trap (below) stay the FIRST line of defense, unchanged.
 * TurnstileGuard is a SECOND, escalating line — invisible to ordinary
 * users — that only kicks in once the requester's IP has repeatedly
 * tripped the existing rate limits (see TurnstileGuard's class doc).
 * Checked right before the honeypot/time-trap fields are read, so a
 * challenged request never even reaches content validation without a
 * verified token.
 *
 * Pre-moderation (broaden-use-cases issue 05): on a board with
 * premoderation != 'off', an idea by an author without an owner/admin/
 * moderator role is stored as 'pending' — AFTER every check above
 * (rate limit, block, standing, Turnstile, honeypot, time trap, validation,
 * word filter all still run first). A pending idea fires none of the
 * publish side effects here (no idea.created webhook); those move to the
 * approval (IdeaModerationQueueAction). Instead the account's moderators get
 * one aggregated in-app notice, and the response additionally carries
 * `moderation_state: "pending"` so the SPA can show the "waiting for
 * approval" state. With premoderation 'off' (default) no role lookup runs
 * and the response is byte-identical to before. Missing role lookup
 * dependency → fail-secure: the idea waits for approval. A trusted author
 * (broaden-use-cases issue 11, account_member_standing.trusted_at) publishes
 * immediately like a moderator; missing standing repository → not trusted.
 *
 * Guests (broaden-use-cases issue 06, ADR 0006): the route's AuthZ is
 * `anon`; a request without a session is handed to AnonymousIdeaSubmission
 * before anything else runs, so this signed-in path stays unchanged.
 */
final readonly class IdeaCreateAction
{
    /** Honeypot form field name. */
    public const HONEYPOT_FIELD = 'website';

    /** Time-Trap form field name. */
    public const TIME_TRAP_FIELD = '_form_at';

    /** Turnstile response-token form field name. */
    public const TURNSTILE_FIELD = 'turnstile_token';

    public function __construct(
        private BoardRepository $boardRepo,
        private IdeaRepository $ideaRepo,
        private TitleNormalizer $normalizer,
        private AuditLogger $audit,
        private ContentModerationService $moderation,
        private TimeTrapService $timeTrap,
        private BlockRepository $blockRepo,
        private TurnstileGuard $turnstileGuard,
        private ?ModerationConfigRepository $moderationConfigRepo = null,
        private ?WebhookDispatcher $webhookDispatcher = null,
        private ?AccountMemberStandingRepository $standingRepo = null,
        private ?AccountMemberRepository $accountMembers = null,
        private ?PremoderationNotifier $premoderationNotifier = null,
        private ?Clock $clock = null,
        private ?AnonymousIdeaSubmission $guestSubmission = null,
        private ?TagRepository $tagRepo = null,
    ) {}

    /**
     * The same action with the guest path wired (first-party route only;
     * the widget mount keeps the instance without it behind AuthZ user).
     */
    public function withGuestSubmission(AnonymousIdeaSubmission $guestSubmission): self
    {
        return new self(
            $this->boardRepo,
            $this->ideaRepo,
            $this->normalizer,
            $this->audit,
            $this->moderation,
            $this->timeTrap,
            $this->blockRepo,
            $this->turnstileGuard,
            $this->moderationConfigRepo,
            $this->webhookDispatcher,
            $this->standingRepo,
            $this->accountMembers,
            $this->premoderationNotifier,
            $this->clock,
            $guestSubmission,
            $this->tagRepo,
        );
    }

    /** @param array<string, mixed> $args */
    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        // No session: the guest path (ADR 0006) decides — it answers with
        // the unchanged AuthZMiddleware::user() 401 whenever guests may not
        // submit here. Without a guest path wired (e.g. the widget mount)
        // the same 401 comes from the route's own AuthZ before this runs.
        if (!is_array($request->getAttribute(AuthNMiddleware::ATTR_USER))) {
            if ($this->guestSubmission instanceof AnonymousIdeaSubmission) {
                return $this->guestSubmission->handle($request, $response, $args);
            }

            // Defensive only (every anon-reachable mount wires the guest path):
            // the same body/status AuthZMiddleware::user() answers with.
            $response->getBody()->write('Access denied.');

            return $response->withStatus(401);
        }

        $slug      = is_string($args['board'] ?? null) ? $args['board'] : '';
        $accountId = (int) $request->getAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID);
        $board     = $this->boardRepo->findBySlugForAccount($slug, $accountId);
        if (!is_array($board)) {
            $response->getBody()->write((string) json_encode([
                'error' => ['key' => 'not_found', 'message' => 'Board not found.'],
            ]));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        if (FrozenBoardGuard::isFrozen($board)) {
            return FrozenBoardGuard::reject($response);
        }

        // Logged-in user — AuthZMiddleware::user() ensures they're present.
        /** @var array<string, mixed> $user */
        $user   = $request->getAttribute(AuthNMiddleware::ATTR_USER);
        $userId = (int) ($user['id'] ?? 0);

        // Optional submission window (broaden-use-cases issue 04, migration
        // 0082). Owner/admin are exempt so they can seed ideas before
        // opening; the role lookup only runs when the window actually
        // refuses (default boards pay no extra query).
        $submissionRejection = BoardPhases::fromBoard($board, ($this->clock ?? new SystemClock())->now())->submissionRejection();
        // Checked before anything is queued (also on pre-moderated boards);
        // without a member repository there is no exemption (fail-secure).
        if ($submissionRejection !== null && !AccountRoles::canAdminister($this->accountMembers?->roleFor($accountId, $userId))) {
            return JsonError::respond($response, 423, $submissionRejection, $submissionRejection === 'submissions_closed'
                ? 'Submitting ideas on this board has closed.'
                : 'Submitting ideas on this board has not opened yet.');
        }

        if ($this->blockRepo->isBlocked($accountId, $userId, (int) $board['id'])) {
            $response->getBody()->write((string) json_encode([
                'error' => ['key' => 'blocked', 'message' => 'You are blocked from this board.'],
            ]));
            return $response->withStatus(403)->withHeader('Content-Type', 'application/json');
        }

        $standing = $this->standingRepo?->findFor($accountId, $userId);
        if (is_array($standing) && ($standing['state'] ?? null) === 'suspended') {
            $response->getBody()->write((string) json_encode([
                'error' => ['key' => 'member_suspended', 'message' => 'You are currently suspended from submitting content in this account.'],
            ]));
            return $response->withStatus(403)->withHeader('Content-Type', 'application/json');
        }

        $parsed = $request->getParsedBody();
        $rawTitle       = is_array($parsed) ? trim((string) ($parsed['title'] ?? '')) : '';
        $rawBody        = is_array($parsed) ? trim((string) ($parsed['body'] ?? '')) : '';
        $honeypot       = is_array($parsed) ? (string) ($parsed[self::HONEYPOT_FIELD] ?? '') : '';
        $timeTrapVal    = is_array($parsed) ? (string) ($parsed[self::TIME_TRAP_FIELD] ?? '') : '';
        $turnstileToken = is_array($parsed) ? (string) ($parsed[self::TURNSTILE_FIELD] ?? '') : '';

        // Bot defense 3 (escalating, see class doc): only engaged once this
        // IP has repeatedly tripped the existing rate limits.
        if ($this->turnstileGuard->requiresChallengeFor($request) && !$this->turnstileGuard->verifyFor($request, $turnstileToken)) {
            $response->getBody()->write((string) json_encode([
                'error' => [
                    'key'      => 'turnstile_required',
                    'message'  => 'Please complete the verification challenge to continue.',
                    'site_key' => $this->turnstileGuard->siteKey(),
                ],
            ]));
            return $response->withStatus(422)->withHeader('Content-Type', 'application/json');
        }

        // Bot defense 1: honeypot field — filled in → silent rejection (422, no hint).
        if ($honeypot !== '') {
            $response->getBody()->write((string) json_encode([
                'error' => ['key' => 'rejected', 'message' => 'The request was rejected.'],
            ]));
            return $response->withStatus(422)->withHeader('Content-Type', 'application/json');
        }

        // Bot defense 2: time trap — too fast → silent rejection (422, no hint).
        if (!$this->timeTrap->verify($timeTrapVal)) {
            $response->getBody()->write((string) json_encode([
                'error' => ['key' => 'rejected', 'message' => 'The request was rejected.'],
            ]));
            return $response->withStatus(422)->withHeader('Content-Type', 'application/json');
        }

        // Validation (shared with the guest path, Http\Support\IdeaInputChecks).
        $fields = IdeaInputChecks::fieldErrors($rawTitle, $rawBody);
        if ($fields !== []) {
            return IdeaInputChecks::validationError($response, $fields, $rawTitle, $rawBody);
        }

        // Optional tags (P-34): existing board tags only, validated before
        // anything is stored, so a rejected submission leaves no idea behind.
        $boardId   = (int) $board['id'];
        $tagInput  = IdeaTagInput::resolve($this->tagRepo, $boardId, is_array($parsed) ? ($parsed['tag_ids'] ?? null) : null);
        if ($tagInput['error'] !== null) {
            return JsonError::respond($response, 422, $tagInput['error'], IdeaTagInput::message($tagInput['error']));
        }

        // Moderation hard block: after structural validation, before the DB entry.
        // Per-board toggle: when "off", only the word filter is skipped —
        // honeypot, time trap, CSRF and rate limit always run (already executed above).
        $modResult = IdeaInputChecks::wordFilter($this->moderation, $this->moderationConfigRepo, $boardId, false, $rawTitle, $rawBody);
        if (!$modResult['clean']) {
            // Log masked — raw hits must never land in the log.
            $this->audit->log('idea.moderation_blocked', [
                'board_id'  => (int) $board['id'],
                'hit_count' => count($modResult['hits']),
            ]);

            return IdeaInputChecks::moderationBlocked($response);
        }

        // Normalization (TitleNormalizer, compatible key format).
        $titleNormalized = $this->normalizer->normalize($rawTitle);

        // Create board-scoped (prepared statement via IdeaRepository).
        $authorId = (int) ($user['id'] ?? 0);

        // 'anonymous' holds back guests only (ADR 0006 §8) — no role lookup needed.
        // A trusted author (issue 11) is read from the standing row already
        // loaded above, so trust costs no extra query.
        $premoderationMode = Premoderation::normalizeMode($board['premoderation'] ?? null);
        $pending           = $premoderationMode === Premoderation::MODE_ALL
            && Premoderation::requiresApproval(
                $premoderationMode,
                $this->accountMembers?->roleFor($accountId, $authorId),
                trusted: Premoderation::isTrusted($standing),
            );

        $ideaId = $this->ideaRepo->create(
            $boardId,
            $authorId,
            $rawTitle,
            $titleNormalized,
            $rawBody,
            $pending ? Premoderation::STATE_PENDING : Premoderation::STATE_PUBLISHED,
        );

        foreach ($tagInput['ids'] as $tagId) {
            $this->tagRepo?->assignToIdea($ideaId, $tagId);
        }

        if ($pending) {
            $this->audit->log('idea.created', ['board_id' => $boardId, 'idea_id' => $ideaId, 'moderation_state' => Premoderation::STATE_PENDING]);

            $boardName = is_string($board['name'] ?? null) ? $board['name'] : $slug;
            $this->premoderationNotifier?->notifyModerators($accountId, $slug, $boardName, $authorId);

            $response->getBody()->write((string) json_encode(['ok' => true, 'id' => $ideaId, 'moderation_state' => Premoderation::STATE_PENDING]));
            return $response->withStatus(201)->withHeader('Content-Type', 'application/json');
        }

        $this->audit->log('idea.created', ['board_id' => $boardId, 'idea_id' => $ideaId]);

        // board-webhooks fan-out: best-effort, deferred (see WebhookDispatcher
        // class doc) — never delays or can fail this response. Payload
        // carries only board-visible fields (idea id + title), no PII
        // (ADR 0002).
        $this->webhookDispatcher?->dispatchAsync($boardId, $slug, 'idea.created', [
            'idea_id' => $ideaId,
            'title'   => $rawTitle,
            'status'  => 'open',
        ]);

        $response->getBody()->write((string) json_encode(['ok' => true, 'id' => $ideaId]));
        return $response->withStatus(201)->withHeader('Content-Type', 'application/json');
    }
}
