<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Http\Middleware\AccountContextMiddleware;
use Votepit\Persistence\AccountRepository;

/**
 * GET /admin/account — the owner-facing account summary behind the SPA's
 * account settings page (data export + self-service deletion).
 *
 * AuthZ: accountOwner (same tier as AccountDeleteAction/AccountExportAction,
 * whose UI this page hosts). Exposes only what that page needs:
 *  - slug/name           — typed-confirmation target for the delete flow
 *  - is_default_account  — a self-host installation's single account is
 *                          undeletable (AccountDeleteAction guards it
 *                          server-side; the SPA hides the danger zone)
 *  - deletion_scheduled_at — pending grace-period deadline, or null
 *
 * Plan/limit information deliberately stays out of this payload — an
 * installation without a plan-limiting extension has no plan to show.
 */
final readonly class AccountSettingsAction
{
    public function __construct(private AccountRepository $accounts) {}

    public function show(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $accountId = (int) $request->getAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID);
        $account   = $this->accounts->findById($accountId);

        if (!is_array($account)) {
            $response->getBody()->write((string) json_encode([
                'error' => ['key' => 'not_found', 'message' => 'Account not found.'],
            ]));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        $response->getBody()->write((string) json_encode([
            'account_id'            => (int) $account['id'],
            'slug'                  => (string) $account['slug'],
            'name'                  => (string) $account['name'],
            'is_default_account'    => (bool) ($account['is_default'] ?? false),
            'deletion_scheduled_at' => $account['deletion_scheduled_at'] ?? null,
        ]));

        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }
}
