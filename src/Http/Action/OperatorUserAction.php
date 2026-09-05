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
