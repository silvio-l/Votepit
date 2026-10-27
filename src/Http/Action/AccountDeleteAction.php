<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Extension\AccountDeletionPrecondition;
use Votepit\Http\Middleware\AccountContextMiddleware;
use Votepit\Logging\AuditLogger;
use Votepit\Persistence\AccountRepository;

/**
 * POST /admin/account/delete        — owner-initiated GDPR Art. 17 erasure
 *                                      request.
 * POST /admin/account/delete/cancel — undo, while the grace period is still
 *                                      running.
 *
 * AuthZ: accountOwner (same tier as BillingAction/invite mutations) on
 * BOTH routes. The typed slug/name confirmation the SPA collects is a UX
 * affordance ONLY (GitHub-style "type to confirm") — it is re-validated
 * here server-side against the account's ACTUAL slug/name, never trusted
 * from the client alone (an attacker who could forge the confirmation text
 * would already need the owner session/CSRF token this route requires
 * anyway, but re-validating costs nothing and keeps the invariant explicit).
 *
 * Grace period: 48h (GDPR Art. 12(3) "without undue delay" — this route
 * is a conscious, explicit owner decision, so no long safety window is
 * needed; an extension scheduling deletions for other reasons may choose a
 * longer one — the deadline is a caller-supplied parameter).
 * Reuses AccountRepository::scheduleDeletion()/clearDeletionSchedule()/
 * purgeExpired() UNCHANGED — the deadline was already a caller-supplied
 * \DateTimeImmutable parameter, so no repository change was needed. The
 * eventual purge is bin/cleanup-expired-accounts.php's existing cron path
 * (AccountRepository::findExpiredForDeletion()); this action never purges
 * anything itself.
 *
 * self-host default account: is_default=1 must never be deletable through
 * this route (it is the ONLY account a self-host installation has) — see
 * $accounts->deleteAccount()/purgeExpired() class docs. Guarded here: a
 * 422, never a silent no-op.
 *
 * Extension hook: AccountDeletionPrecondition::beforeScheduling() runs
 * after the confirmation is validated and BEFORE anything is written. The
 * Community default (NullAccountDeletionPrecondition) always allows; a
 * hosted-service extension uses it to settle external state first (e.g.
 * cancel a paid subscription at the payment provider). If the precondition
 * blocks, this whole request fails closed with the status/key/message it
 * returns — deletion is never scheduled with unsettled external state.
 */
final readonly class AccountDeleteAction
{
    private const GRACE_PERIOD = '+48 hours';

    public function __construct(
        private AccountRepository $accounts,
        private AccountDeletionPrecondition $precondition,
        private AuditLogger $audit,
    ) {}

    public function requestDeletion(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $accountId = (int) $request->getAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID);
        $account   = $this->accounts->findById($accountId);

        if (!is_array($account)) {
            return $this->json($response, 404, ['error' => ['key' => 'not_found', 'message' => 'Account not found.']]);
        }

        if ((bool) ($account['is_default'] ?? false)) {
            return $this->json($response, 422, [
                'error' => [
                    'key'     => 'default_account_undeletable',
                    'message' => 'The self-host default account cannot be deleted.',
                ],
            ]);
        }

        $parsed      = $request->getParsedBody();
        $confirmSlug = is_array($parsed) ? trim((string) ($parsed['confirm_slug'] ?? '')) : '';

        // Re-validated server-side against the REAL slug — the client-typed
        // text is UX only (see class doc).
        if ($confirmSlug === '' || !hash_equals((string) $account['slug'], $confirmSlug)) {
            return $this->json($response, 422, [
                'error' => [
                    'key'     => 'confirmation_mismatch',
                    'message' => 'To confirm, please enter the exact slug of this account.',
                ],
            ]);
        }

        $blocked = $this->precondition->beforeScheduling($account);
        if ($blocked instanceof \Votepit\Extension\DeletionBlocked) {
            $this->audit->log('account.delete.blocked', [
                'account_id' => $accountId,
                'key'        => $blocked->key,
            ]);

            return $this->json($response, $blocked->httpStatus, [
                'error' => ['key' => $blocked->key, 'message' => $blocked->message],
            ]);
        }

        $deadline = new \DateTimeImmutable(self::GRACE_PERIOD);
        $this->accounts->scheduleDeletion($accountId, $deadline);

        $this->audit->log('account.delete.requested', [
            'account_id' => $accountId,
            'deadline'   => $deadline->format('Y-m-d H:i:s'),
        ]);

        return $this->json($response, 200, [
            'ok'                     => true,
            'deletion_scheduled_at'  => $deadline->format(DATE_ATOM),
        ]);
    }

    public function cancelDeletion(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $accountId = (int) $request->getAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID);
        $account   = $this->accounts->findById($accountId);

        if (!is_array($account)) {
            return $this->json($response, 404, ['error' => ['key' => 'not_found', 'message' => 'Account not found.']]);
        }

        $this->accounts->clearDeletionSchedule($accountId);

        $this->audit->log('account.delete.canceled', ['account_id' => $accountId]);

        return $this->json($response, 200, ['ok' => true]);
    }

    /** @param array<string, mixed> $payload */
    private function json(ResponseInterface $response, int $status, array $payload): ResponseInterface
    {
        $response->getBody()->write((string) json_encode($payload));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
