<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Http\Middleware\AccountContextMiddleware;
use Votepit\Http\Middleware\AuthNMiddleware;
use Votepit\Logging\AuditLogger;
use Votepit\Persistence\AccountMemberRepository;
use Votepit\Persistence\InviteRepository;
use Votepit\Persistence\UserRepository;
use Votepit\Security\IdentityHasher;
use Votepit\Security\PasswordResetMailer;

/**
 * GET  /admin/members — list an account's members + open invites
 * (AuthZ: accountAdmin — owner AND admin may see the list; moderator is
 * restricted to comment/idea moderation only and does not pass accountAdmin,
 * see AuthZMiddleware::accountModerate()). The account's OWNER is never
 * included in the returned members (see list() below) — there is nothing to
 * remove/re-role on that row via this UI, so it would only invite confusion.
 * Response additionally carries `viewer_role`, so the SPA can gate the
 * owner-only UI (invite form, remove, role change, revoke) without a second
 * bootstrap round trip.
 *
 * POST /admin/members/{userId}/remove — remove a member (AuthZ: accountOwner).
 * The account's owner cannot be removed (there is exactly one, always) → 422.
 *
 * POST /admin/members/{userId}/role — change role (admin|moderator|member,
 * AuthZ: accountOwner). 'owner' is not an accepted target: account_members
 * enforces EXACTLY one owner per account (chk_account_members_role plus this
 * endpoint never creating or removing an 'owner' row) — ownership transfer
 * is a deliberately separate, not-yet-built flow, not a role change.
 *
 * No PII in the response/audit log (ADR 0002): members are identified only via
 * user_id — the DB never holds a plaintext email at this point.
 *
 * POST /admin/members/password-reset — trigger a mail-based password-reset
 * link for one of the account's members (AuthZ: accountAdmin). Body:
 * { email }. Like AccountPasswordResetAction, the target's plaintext email
 * isn't stored (ADR 0002), so the caller re-types it; the resolved user must
 * additionally be a member of THIS account — anything else (unknown email,
 * or an email belonging to a foreign account's user) collapses into the
 * same 404, so this never becomes a cross-tenant existence oracle.
 */
final readonly class MemberAction
{
    public function __construct(
        private AccountMemberRepository $members,
        private InviteRepository $invites,
        private UserRepository $userRepo,
        private IdentityHasher $hasher,
        private PasswordResetMailer $resetMailer,
        private AuditLogger $audit,
    ) {}

    public function list(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $accountId  = (int) $request->getAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID);
        $actorId    = $this->actorId($request);
        $viewerRole = $this->members->roleFor($accountId, $actorId);

        // The owner never appears in their own members list — see class doc.
        $members = array_values(array_filter(
            $this->members->listForAccount($accountId),
            static fn (array $member): bool => $member['role'] !== 'owner',
        ));

        $response->getBody()->write((string) json_encode([
            'members'     => $members,
            'invites'     => $this->invites->listPendingForAccount($accountId),
            'viewer_role' => $viewerRole,
        ]));
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }

    /** @param array<string, mixed> $args */
    public function remove(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $accountId    = (int) $request->getAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID);
        $targetUserId = is_numeric($args['userId'] ?? null) ? (int) $args['userId'] : 0;

        $targetRole = $targetUserId > 0 ? $this->members->roleFor($accountId, $targetUserId) : null;
        if ($targetRole === null) {
            return $this->json($response, 404, ['error' => ['key' => 'not_found', 'message' => 'Member not found.']]);
        }

        if ($targetRole === 'owner') {
            return $this->json($response, 422, [
                'error' => ['key' => 'last_owner', 'message' => 'The owner of an account cannot be removed.'],
            ]);
        }

        $this->members->removeMember($accountId, $targetUserId);

        $this->audit->log('member.removed', [
            'account_id'     => $accountId,
            'target_user_id' => $targetUserId,
            'actor_id'       => $this->actorId($request),
        ]);

        return $this->json($response, 200, ['ok' => true]);
    }

    /** @param array<string, mixed> $args */
    public function changeRole(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $accountId    = (int) $request->getAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID);
        $targetUserId = is_numeric($args['userId'] ?? null) ? (int) $args['userId'] : 0;

        $parsed  = $request->getParsedBody();
        $newRole = is_array($parsed) ? (string) ($parsed['role'] ?? '') : '';

        if (!in_array($newRole, ['admin', 'moderator', 'member'], true)) {
            return $this->json($response, 422, [
                'error' => ['key' => 'invalid_input', 'message' => 'role must be "admin", "moderator" or "member".'],
            ]);
        }

        $currentRole = $targetUserId > 0 ? $this->members->roleFor($accountId, $targetUserId) : null;
        if ($currentRole === null) {
            return $this->json($response, 404, ['error' => ['key' => 'not_found', 'message' => 'Member not found.']]);
        }

        if ($currentRole === 'owner') {
            return $this->json($response, 422, [
                'error' => ['key' => 'last_owner', 'message' => 'The owner of an account cannot be re-roled.'],
            ]);
        }

        if ($currentRole === $newRole) {
            return $this->json($response, 200, ['ok' => true, 'role' => $newRole]);
        }

        $this->members->addMember($accountId, $targetUserId, $newRole);

        $this->audit->log('member.role_changed', [
            'account_id'     => $accountId,
            'target_user_id' => $targetUserId,
            'new_role'       => $newRole,
            'actor_id'       => $this->actorId($request),
        ]);

        return $this->json($response, 200, ['ok' => true, 'role' => $newRole]);
    }

    public function passwordReset(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $accountId = (int) $request->getAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID);

        $parsed = $request->getParsedBody();
        $email  = is_array($parsed) ? strtolower(trim((string) ($parsed['email'] ?? ''))) : '';

        $user = $email !== '' ? $this->userRepo->findByEmailHmac($this->hasher->hash($email)) : null;
        $targetUserId = is_array($user) ? (int) $user['id'] : 0;

        if ($targetUserId === 0 || $this->members->roleFor($accountId, $targetUserId) === null) {
            return $this->json($response, 404, [
                'error' => ['key' => 'not_found', 'message' => 'No member of this account matches that email.'],
            ]);
        }

        $this->resetMailer->send($targetUserId, $email);

        $this->audit->log('member.password_reset_requested', [
            'account_id'     => $accountId,
            'target_user_id' => $targetUserId,
            'actor_id'       => $this->actorId($request),
        ]);

        return $this->json($response, 200, ['ok' => true]);
    }

    private function actorId(ServerRequestInterface $request): int
    {
        /** @var array<string, mixed>|null $actor */
        $actor = $request->getAttribute(AuthNMiddleware::ATTR_USER);
        return is_array($actor) ? (int) ($actor['id'] ?? 0) : 0;
    }

    /** @param array<string, mixed> $payload */
    private function json(ResponseInterface $response, int $status, array $payload): ResponseInterface
    {
        $response->getBody()->write((string) json_encode($payload));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
