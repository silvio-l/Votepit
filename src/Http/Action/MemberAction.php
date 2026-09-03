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

/**
 * GET  /admin/members — list an account's members + open invites
 * (AuthZ: accountAdmin — owner AND moderator may see the list,
 * only the mutations below are owner-only). Response additionally carries
 * `viewer_role`, so the SPA can gate the owner-only UI (invite form, remove,
 * role change, revoke) without a second bootstrap round trip.
 *
 * POST /admin/members/{userId}/remove — remove a member (AuthZ: accountOwner).
 * The last remaining owner of an account CANNOT be removed
 * (invariant: at least one owner) → 422.
 *
 * POST /admin/members/{userId}/role — change role (owner|moderator, AuthZ:
 * accountOwner). Demoting the last owner is likewise forbidden → 422.
 * account_members(role) structurally allows multiple owners (no unique-owner
 * constraint) — multi-owner is thus a deliberate extension derived from the
 * existing schema, not a new invention.
 *
 * No PII in the response/audit log (ADR 0002): members are identified only via
 * user_id — the DB never holds a plaintext email at this point.
 */
final readonly class MemberAction
{
    public function __construct(
        private AccountMemberRepository $members,
        private InviteRepository $invites,
        private AuditLogger $audit,
    ) {}

    public function list(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $accountId  = (int) $request->getAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID);
        $actorId    = $this->actorId($request);
        $viewerRole = $this->members->roleFor($accountId, $actorId);

        $response->getBody()->write((string) json_encode([
            'members'     => $this->members->listForAccount($accountId),
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

        if ($targetRole === 'owner' && $this->members->countOwners($accountId) <= 1) {
            return $this->json($response, 422, [
                'error' => ['key' => 'last_owner', 'message' => 'The last owner of an account cannot be removed.'],
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

        if (!in_array($newRole, ['owner', 'moderator'], true)) {
            return $this->json($response, 422, [
                'error' => ['key' => 'invalid_input', 'message' => 'role must be "owner" or "moderator".'],
            ]);
        }

        $currentRole = $targetUserId > 0 ? $this->members->roleFor($accountId, $targetUserId) : null;
        if ($currentRole === null) {
            return $this->json($response, 404, ['error' => ['key' => 'not_found', 'message' => 'Member not found.']]);
        }

        if ($currentRole === 'owner' && $newRole === 'moderator' && $this->members->countOwners($accountId) <= 1) {
            return $this->json($response, 422, [
                'error' => ['key' => 'last_owner', 'message' => 'The last owner of an account cannot be demoted.'],
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
