<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Http\Middleware\AccountContextMiddleware;
use Votepit\Persistence\AccountRepository;

/**
 * POST /admin/onboarding/complete — marks the Setup Wizard (BoardsAdminPage.tsx)
 * as done for the current account, whether the admin finished it ("Done")
 * or explicitly skipped it ("Skip") — the SPA doesn't distinguish the
 * two server-side, both just mean "don't show the wizard again".
 *
 * AuthZ: accountAdmin (owner AND moderator — same tier as GET/POST
 * /admin/boards, which the wizard also calls to create the first board).
 * Idempotent: AccountRepository::markOnboardingCompleted() is a no-op once
 * already set, so a retried/duplicate call is harmless.
 */
final readonly class OnboardingCompleteAction
{
    public function __construct(private AccountRepository $accountRepo) {}

    public function complete(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $accountId = (int) $request->getAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID);
        $this->accountRepo->markOnboardingCompleted($accountId);

        $response->getBody()->write((string) json_encode(['ok' => true]));
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }
}
