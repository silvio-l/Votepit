<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Http\Middleware\AccountContextMiddleware;
use Votepit\Persistence\AccountRepository;

/**
 * POST /admin/telemetry-opt-in — records the Setup Wizard's
 * product-improvement-telemetry consent decision (accept OR decline; see
 * AccountRepository::setTelemetryDecision()). Body: {"opted_in": bool}.
 * Accepting and declining are equally valid, equally reachable outcomes —
 * this endpoint does not treat one as more "correct" than the other; the
 * SPA's Setup Wizard step must present both choices with identical
 * prominence (Art. 7(4) GDPR: consent must not be a condition for using
 * the wizard/app).
 *
 * AuthZ: accountAdmin — same tier as the rest of the Setup Wizard's calls
 * (OnboardingCompleteAction, BoardCreateAction).
 */
final readonly class TelemetryOptInAction
{
    public function __construct(private AccountRepository $accountRepo) {}

    public function optIn(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $accountId = (int) $request->getAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID);
        $body      = (array) $request->getParsedBody();
        $optedIn   = (bool) ($body['opted_in'] ?? false);

        $this->accountRepo->setTelemetryDecision($accountId, $optedIn);

        $response->getBody()->write((string) json_encode(['ok' => true, 'opted_in' => $optedIn]));
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }
}
