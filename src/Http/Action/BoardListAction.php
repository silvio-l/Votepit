<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Http\Middleware\AccountContextMiddleware;
use Votepit\Persistence\AccountRepository;
use Votepit\Persistence\BoardRepository;

/**
 * GET /admin/boards — board overview of the current account (AuthZ: accountAdmin).
 *
 * Read path for the admin overview page: returns exclusively the boards of
 * the account resolved via AccountContextMiddleware (id, slug, name). No
 * cross-tenant leak possible, since BoardRepository::listForAccount()
 * strictly filters by account_id.
 *
 * Onboarding follow-up: also returns `account.onboarding_completed_at` —
 * BoardsAdminPage.tsx uses it (together with an empty board list) to decide
 * whether to render the first-run Setup Wizard instead of the normal board
 * list/create-form. Piggy-backing on this existing round trip avoids a
 * second bootstrap-shaped endpoint just for one flag.
 */
final readonly class BoardListAction
{
    public function __construct(
        private BoardRepository $boardRepo,
        private AccountRepository $accountRepo,
    ) {}

    public function list(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $accountId = (int) $request->getAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID);
        $boards    = $this->boardRepo->listForAccount($accountId);
        $account   = $this->accountRepo->findById($accountId);

        $response->getBody()->write((string) json_encode([
            'boards'  => $boards,
            'account' => [
                'onboarding_completed_at' => is_array($account) ? $account['onboarding_completed_at'] : null,
            ],
        ]));
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }
}
