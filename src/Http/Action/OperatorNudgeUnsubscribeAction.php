<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Logging\AuditLogger;
use Votepit\Persistence\OperatorNudgeRepository;
use Votepit\Security\OperatorNudgeUnsubscribeToken;

/**
 * POST /operator-nudges/unsubscribe — body: { token } (AuthZ: anon, CSRF, IP rate-limited).
 *
 * One-click unsubscribe of ONE operator nudge kind, same posture as the
 * weekly digest's POST /digest/unsubscribe: the mail links to the SPA page
 * /operator-nudges/unsubscribe?token=…, which posts the token here on load.
 * A GET never changes anything. The token can only switch its own kind OFF
 * for its own user — never on, never another kind. Invalid or forged tokens
 * get one constant 400 (fail-secure).
 */
final readonly class OperatorNudgeUnsubscribeAction
{
    public function __construct(
        private OperatorNudgeRepository $nudges,
        private OperatorNudgeUnsubscribeToken $tokens,
        private AuditLogger $audit,
    ) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $parsed = $request->getParsedBody();
        $token  = is_array($parsed) && is_string($parsed['token'] ?? null) ? $parsed['token'] : '';
        $claim  = $token !== '' ? $this->tokens->verify($token) : null;

        if ($claim === null) {
            $response->getBody()->write((string) json_encode([
                'error' => ['key' => 'invalid_token', 'message' => 'The link is invalid.'],
            ]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        $this->nudges->setPreference($claim['user_id'], $claim['kind'], false);
        $this->audit->log('user.operator_nudge_unsubscribed', ['user_id' => $claim['user_id'], 'kind' => $claim['kind']->value]);

        $response->getBody()->write((string) json_encode(['ok' => true, 'kind' => $claim['kind']->value]));
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }
}
