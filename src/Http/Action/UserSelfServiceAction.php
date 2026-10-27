<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Domain\UserDataExportService;
use Votepit\Domain\UserDeletionGuard;
use Votepit\Extension\DeletionBlocked;
use Votepit\Http\Middleware\AuthNMiddleware;
use Votepit\Logging\AuditLogger;
use Votepit\Persistence\UserRepository;
use Votepit\Security\IdentityHasher;

/**
 * GDPR self-service for every user (competitor-parity issue 12), available
 * on every plan and in self-host:
 *
 * GET  /account/export        — download of the caller's own data (JSON,
 *                               UserDataExportService).
 * POST /account/delete        — schedules erasure of the caller's own user
 *                               account after a 48h grace period (same
 *                               window as AccountDeleteAction).
 * POST /account/delete/cancel — undo while the grace period runs.
 *
 * AuthZ: user on all three; the target is always the session user, never
 * client input. User-scoped, not account-scoped (identity is global, ADR
 * 0001 §2c).
 *
 * Re-authentication: the caller must type their own email address. It is
 * HMAC'd server-side and compared with users.email_hmac (ADR 0002 — the
 * plaintext is never stored or logged). A stolen session cookie alone is
 * therefore not enough to trigger the deletion.
 *
 * Preconditions (UserDeletionGuard): account owners and operators are
 * blocked with a clear 409 — re-checked at purge time by ExpiredUserCleaner.
 * What the purge does is documented on UserErasureRepository.
 */
final readonly class UserSelfServiceAction
{
    private const GRACE_PERIOD = '+48 hours';

    public function __construct(
        private UserRepository $users,
        private UserDataExportService $export,
        private UserDeletionGuard $guard,
        private IdentityHasher $hasher,
        private AuditLogger $audit,
    ) {}

    public function export(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $userId = $this->currentUserId($request);
        $data   = $this->export->build($userId);
        $stamp  = (new \DateTimeImmutable())->format('Ymd-His');

        $this->audit->log('user.export', ['user_id' => $userId]);

        $response->getBody()->write((string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $response
            ->withStatus(200)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Content-Disposition', sprintf('attachment; filename="votepit-my-data-%s.json"', $stamp));
    }

    public function requestDeletion(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $userId = $this->currentUserId($request);
        $user   = $this->users->findById($userId);
        if (!is_array($user)) {
            return $this->json($response, 404, ['error' => ['key' => 'not_found', 'message' => 'User not found.']]);
        }

        $parsed       = $request->getParsedBody();
        $confirmEmail = is_array($parsed) && is_string($parsed['confirm_email'] ?? null) ? trim($parsed['confirm_email']) : '';

        if ($confirmEmail === '' || !hash_equals((string) $user['email_hmac'], $this->hasher->hash($confirmEmail))) {
            return $this->json($response, 422, [
                'error' => [
                    'key'     => 'confirmation_mismatch',
                    'message' => 'To confirm, please enter the email address you sign in with.',
                ],
            ]);
        }

        $blocked = $this->guard->check($userId);
        if ($blocked instanceof DeletionBlocked) {
            $this->audit->log('user.delete.blocked', ['user_id' => $userId, 'key' => $blocked->key]);

            return $this->json($response, $blocked->httpStatus, [
                'error' => ['key' => $blocked->key, 'message' => $blocked->message],
            ]);
        }

        $deadline = new \DateTimeImmutable(self::GRACE_PERIOD);
        $this->users->scheduleDeletion($userId, $deadline);

        $this->audit->log('user.delete.requested', [
            'user_id'  => $userId,
            'deadline' => $deadline->format('Y-m-d H:i:s'),
        ]);

        return $this->json($response, 200, [
            'ok'                    => true,
            'deletion_scheduled_at' => $deadline->format(DATE_ATOM),
        ]);
    }

    public function cancelDeletion(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $userId = $this->currentUserId($request);
        $this->users->clearDeletionSchedule($userId);

        $this->audit->log('user.delete.canceled', ['user_id' => $userId]);

        return $this->json($response, 200, ['ok' => true]);
    }

    private function currentUserId(ServerRequestInterface $request): int
    {
        $user = $request->getAttribute(AuthNMiddleware::ATTR_USER);

        // AuthZMiddleware::user() guarantees an authenticated user; fail
        // closed anyway rather than act on user id 0.
        $id = is_array($user) ? (int) ($user['id'] ?? 0) : 0;
        if ($id <= 0) {
            throw new \LogicException('UserSelfServiceAction requires an authenticated user.');
        }

        return $id;
    }

    /** @param array<string, mixed> $payload */
    private function json(ResponseInterface $response, int $status, array $payload): ResponseInterface
    {
        $response->getBody()->write((string) json_encode($payload));

        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
