<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Domain\AccountPlanResolver;
use Votepit\Domain\PlanPolicy;
use Votepit\Http\Middleware\AuthNMiddleware;
use Votepit\Logging\AuditLogger;
use Votepit\Persistence\AccountMemberRepository;
use Votepit\Persistence\AccountRepository;
use Votepit\Persistence\InviteRepository;
use Votepit\Security\TokenVault;

/**
 * GET /invite/accept?token=<plaintext> — accepts an invite.
 * AuthZ: user (anon → 401; the SPA then redirects via /login?r=…, the
 * magic-link login lands back on this page after verification —
 * identical to the existing return_to pattern in LoginVerifyAction/VerifyPage).
 * GET → CSRF-exempt: the one-time token itself is the capability (analogous to
 * GET /login/verify).
 *
 * Ownership check: invites.user_id MUST match the logged-in user
 * — the invite is bound to a specific email (already resolved to a
 * users row when the invite was sent), not to "whoever clicks first".
 * A mismatch (a different session opened the link) → 403, no side effect.
 *
 * On success: an account_members row is created (role ALWAYS taken from the
 * invite, in practice always 'moderator' — see InviteAction), token is consumed.
 *
 * Team-size plan-limit re-check (OWASP/BSI audit remediation issue 18):
 * InviteAction's send-time check alone doesn't prevent two ALREADY-SENT
 * invites from both being accepted past the team cap (sending an invite
 * doesn't itself consume a member slot) — this is the point membership
 * actually grows, so it's also the point that must be race-safe.
 * AccountMemberRepository::addMemberWithinLimit() re-checks the limit
 * inside the same locked transaction as the INSERT. If the account is at
 * capacity, the invite is left UNCONSUMED (no markUsed()) so the invited
 * user can retry the same link later if a slot opens up, instead of
 * needing a brand new invite.
 */
final readonly class InviteAcceptAction
{
    /** See LoginVerifyAction::REPLAY_GRACE_SECONDS — identical compensation, same value. */
    private const REPLAY_GRACE_SECONDS = 120;

    public function __construct(
        private InviteRepository $invites,
        private AccountMemberRepository $members,
        private AccountRepository $accounts,
        private AccountPlanResolver $plans,
        private PlanPolicy $planPolicy,
        private TokenVault $vault,
        private AuditLogger $audit,
    ) {}

    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $params = $request->getQueryParams();
        $token  = is_string($params['token'] ?? null) ? $params['token'] : '';

        $row = $token !== '' ? $this->invites->findActiveByHash($this->vault->hash($token)) : null;

        // Mail security gateway prescanning compensation (see REPLAY_GRACE_SECONDS +
        // InviteRepository::findRecentlyUsedByHash) — addMember()/markUsed() stay
        // idempotent, so a replay here is harmless.
        if (!is_array($row) && $token !== '') {
            $row = $this->invites->findRecentlyUsedByHash($this->vault->hash($token), self::REPLAY_GRACE_SECONDS);
        }

        if (!is_array($row) || !$this->vault->verify($token, (string) $row['token_hash'])) {
            $this->audit->log('invite.accept_failed', []);
            return $this->json($response, 400, [
                'error' => ['key' => 'invalid_token', 'message' => 'The invitation link is invalid or has expired.'],
            ]);
        }

        /** @var array<string, mixed>|null $actor */
        $actor   = $request->getAttribute(AuthNMiddleware::ATTR_USER);
        $actorId = is_array($actor) ? (int) ($actor['id'] ?? 0) : 0;

        if ($actorId !== (int) $row['user_id']) {
            // Diagnostic only — no PII: distinguishes a genuine alias/wrong-
            // address mismatch from a session-resolution bug (e.g. a stale
            // duplicate cookie shadowing the freshly logged-in session) when
            // investigating a report of this error.
            $this->audit->log('invite.accept_mismatch', [
                'invite_id'       => (int) $row['id'],
                'account_id'      => (int) $row['account_id'],
                'invited_user_id' => (int) $row['user_id'],
                'actor_id'        => $actorId,
            ]);

            return $this->json($response, 403, [
                'error' => [
                    'key'     => 'invite_mismatch',
                    'message' => 'This invitation is intended for a different email address. Please log in with the invited account.',
                ],
            ]);
        }

        $accountId = (int) $row['account_id'];
        $role      = (string) $row['role'];
        $inviteId  = (int) $row['id'];

        // The account-level operator bypass applies here too: an invite the
        // operator sent from their own account must stay acceptable.
        $plan    = $this->plans->forAccount($accountId, $actor);
        $limit   = $this->planPolicy->memberLimit($plan);

        $added = $this->members->addMemberWithinLimit($accountId, $actorId, $role, $limit);
        if (!$added) {
            // Deliberately left UNCONSUMED (no markUsed()) — the invited user
            // can retry the same link once a slot opens up, instead of
            // needing the owner to re-invite them.
            $this->audit->log('invite.accept_blocked_by_team_limit', [
                'account_id' => $accountId,
                'user_id'    => $actorId,
            ]);

            return $this->json($response, 422, [
                'error' => [
                    'key'     => 'plan_limit_team',
                    'message' => 'This account has reached its plan\'s team-size limit. Please contact the account owner.',
                ],
            ]);
        }

        $this->invites->markUsed($inviteId);

        $account = $this->accounts->findById($accountId);

        $this->audit->log('invite.accepted', [
            'account_id' => $accountId,
            'user_id'    => $actorId,
        ]);

        return $this->json($response, 200, [
            'ok'           => true,
            'account_id'   => $accountId,
            'account_slug' => $account !== null ? $account['slug'] : null,
            'role'         => $role,
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function json(ResponseInterface $response, int $status, array $payload): ResponseInterface
    {
        $response->getBody()->write((string) json_encode($payload));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
