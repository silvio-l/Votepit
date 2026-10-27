<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Http\Middleware\AccountContextMiddleware;
use Votepit\Http\Middleware\AuthNMiddleware;
use Votepit\Logging\AuditLogger;
use Votepit\Persistence\AccountMemberRepository;
use Votepit\Persistence\AccountMemberStandingRepository;

/**
 * PATCH /admin/members/{userId}/standing — manual override of a member's
 * account_member_standing (board-operator-compliance issue 10). AuthZ:
 * accountModerate (owner|admin|moderator — deliberately NOT accountAdmin-
 * only like MemberAction's remove()/changeRole(): the PRD's user story 24
 * explicitly gives moderators this control, since it's a moderation
 * decision, not an account-management one).
 *
 * Body: { state }, one of 'normal'|'flagged'|'warned'|'suspended'.
 * Bypasses Domain\ModerationStandingService entirely — sets state directly
 * via AccountMemberStandingRepository::setManual(), which stamps
 * state_changed_by_user_id with the acting user's ID (the distinguishing
 * marker vs. the automatic path's NULL, see that repository's class doc).
 *
 * Target user not a member of this account → 404 (same "unknown ==
 * cross-tenant" collapse as MemberAction's routes; standing itself is
 * account-scoped by primary key, so there is no separate leak surface to
 * guard beyond the membership check).
 *
 * Always responds JSON { ok: true, state: string } (status 200).
 */
final readonly class AccountMemberStandingAction
{
    private const ALLOWED_STATES = ['normal', 'flagged', 'warned', 'suspended'];

    public function __construct(
        private AccountMemberRepository $members,
        private AccountMemberStandingRepository $standingRepo,
        private AuditLogger $audit,
    ) {}

    /** @param array<string, mixed> $args */
    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $accountId    = (int) $request->getAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID);
        $targetUserId = is_numeric($args['userId'] ?? null) ? (int) $args['userId'] : 0;

        if ($targetUserId <= 0 || $this->members->roleFor($accountId, $targetUserId) === null) {
            return $this->json($response, 404, [
                'error' => ['key' => 'not_found', 'message' => 'Member not found.'],
            ]);
        }

        $parsed = $request->getParsedBody();
        $state  = is_array($parsed) ? trim((string) ($parsed['state'] ?? '')) : '';

        if (!in_array($state, self::ALLOWED_STATES, true)) {
            return $this->json($response, 422, [
                'error' => ['key' => 'invalid_input', 'message' => 'state must be one of: ' . implode(', ', self::ALLOWED_STATES) . '.'],
            ]);
        }

        /** @var array<string, mixed>|null $actor */
        $actor   = $request->getAttribute(AuthNMiddleware::ATTR_USER);
        $actorId = is_array($actor) ? (int) ($actor['id'] ?? 0) : 0;

        $trustRevoked = $this->standingRepo->setManual($accountId, $targetUserId, $state, $actorId);

        $this->audit->log('member.standing_manually_changed', [
            'account_id'     => $accountId,
            'target_user_id' => $targetUserId,
            'state'          => $state,
            'actor_id'       => $actorId,
        ]);

        // Losing good standing clears a trusted-author mark (issue 11);
        // going back to normal never restores it.
        if ($trustRevoked) {
            $this->audit->log('member.trust_revoked', [
                'account_id' => $accountId,
                'user_id'    => $targetUserId,
                'actor_id'   => $actorId,
                'reason'     => 'standing_' . $state,
            ]);
        }

        return $this->json($response, 200, ['ok' => true, 'state' => $state]);
    }

    /** @param array<string, mixed> $payload */
    private function json(ResponseInterface $response, int $status, array $payload): ResponseInterface
    {
        $response->getBody()->write((string) json_encode($payload));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
