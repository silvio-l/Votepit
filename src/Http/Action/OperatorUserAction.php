<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Http\Middleware\AuthNMiddleware;
use Votepit\Logging\AuditLogger;
use Votepit\Persistence\UserRepository;
use Votepit\Security\IdentityHasher;
use Votepit\Security\PasswordResetMailer;

/**
 * POST /operator/users/password-reset — trigger a mail-based password-reset
 * link for ANY platform user (Punkt 5d). AuthZ: AuthZMiddleware::support()
 * — is_support OR is_operator, strictly above account-scoping (same tier as
 * OperatorAccountAction, see its class doc), so no account-membership check
 * applies here.
 *
 * Body: { email }. As with MemberAction::passwordReset() and
 * AccountPasswordResetAction, the target's plaintext email isn't stored
 * (ADR 0002) — the operator/support agent re-types the address they already
 * have from the support conversation. An unknown address returns 404 (this
 * tier is not attacker-facing, so anti-enumeration masking is unnecessary
 * here — unlike the public POST /password/reset/request).
 */
final readonly class OperatorUserAction
{
    public function __construct(
        private UserRepository $userRepo,
        private IdentityHasher $hasher,
        private PasswordResetMailer $resetMailer,
        private AuditLogger $audit,
    ) {}

    public function passwordReset(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $parsed = $request->getParsedBody();
        $email  = is_array($parsed) ? strtolower(trim((string) ($parsed['email'] ?? ''))) : '';

        $user = $email !== '' ? $this->userRepo->findByEmailHmac($this->hasher->hash($email)) : null;
        if (!is_array($user)) {
            return $this->json($response, 404, [
                'error' => ['key' => 'not_found', 'message' => 'No user matches that email.'],
            ]);
        }

        $targetUserId = (int) $user['id'];
        $this->resetMailer->send($targetUserId, $email);

        $this->audit->log('operator.user.password_reset_requested', [
            'actor_tier'     => 'operator',
            'actor_id'       => $this->actorId($request),
            'target_user_id' => $targetUserId,
        ]);

        return $this->json($response, 200, ['ok' => true]);
    }

    /**
     * POST /operator/users/block — sets/clears the global, installation-wide
     * kill switch (`users.is_blocked`, see BlockCheckMiddleware class doc).
     * Body: { email, blocked }. Target resolved by re-typed email, same
     * pattern as passwordReset() above (ADR 0002: no plaintext email
     * stored). Blocking also bumps token_version
     * (UserRepository::setBlocked), invalidating every existing session of
     * the target user immediately — not just future mutations.
     */
    public function block(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $parsed = $request->getParsedBody();
        $email  = is_array($parsed) ? strtolower(trim((string) ($parsed['email'] ?? ''))) : '';

        $target = $email !== '' ? $this->userRepo->findByEmailHmac($this->hasher->hash($email)) : null;
        if (!is_array($target)) {
            return $this->json($response, 404, ['error' => ['key' => 'not_found', 'message' => 'No user matches that email.']]);
        }

        $blocked = $this->parseBlocked(is_array($parsed) ? ($parsed['blocked'] ?? null) : null);
        if ($blocked === null) {
            return $this->json($response, 422, ['error' => ['key' => 'invalid_input', 'message' => 'Invalid blocked value.']]);
        }

        $targetUserId = (int) $target['id'];
        $this->userRepo->setBlocked($targetUserId, $blocked);

        $this->audit->log('operator.user.block_changed', [
            'actor_tier'     => 'operator',
            'actor_id'       => $this->actorId($request),
            'target_user_id' => $targetUserId,
            'blocked'        => $blocked,
        ]);

        return $this->json($response, 200, ['ok' => true, 'blocked' => $blocked]);
    }

    /**
     * POST /operator/users/end-sessions — bumps token_version for a target
     * user (resolved by re-typed email, same as block()/passwordReset()),
     * without touching is_blocked. Used for a suspected account-takeover:
     * forces every existing session to be rejected by AuthNMiddleware
     * without locking the account out entirely.
     */
    public function endSessions(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $parsed = $request->getParsedBody();
        $email  = is_array($parsed) ? strtolower(trim((string) ($parsed['email'] ?? ''))) : '';

        $target = $email !== '' ? $this->userRepo->findByEmailHmac($this->hasher->hash($email)) : null;
        if (!is_array($target)) {
            return $this->json($response, 404, ['error' => ['key' => 'not_found', 'message' => 'No user matches that email.']]);
        }

        $targetUserId = (int) $target['id'];
        $this->userRepo->bumpTokenVersion($targetUserId);

        $this->audit->log('operator.user.sessions_ended', [
            'actor_tier'     => 'operator',
            'actor_id'       => $this->actorId($request),
            'target_user_id' => $targetUserId,
        ]);

        return $this->json($response, 200, ['ok' => true]);
    }

    /**
     * Strictly validates a bool-ish field: real bool, or '1'/1/'true'
     * resp. '0'/0/'false'. Anything else → null.
     */
    private function parseBlocked(mixed $raw): ?bool
    {
        if (is_bool($raw)) {
            return $raw;
        }

        return match (true) {
            in_array($raw, ['1', 1, 'true'], true)  => true,
            in_array($raw, ['0', 0, 'false'], true) => false,
            default                                 => null,
        };
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
