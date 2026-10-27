<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Logging\AuditLogger;
use Votepit\Persistence\UserRepository;
use Votepit\Security\DigestUnsubscribeToken;

/**
 * POST /digest/unsubscribe — body: { token } (AuthZ: anon, CSRF, IP rate-limited).
 *
 * The weekly owner digest's one-click unsubscribe: the mail links to the SPA
 * page /digest/unsubscribe?token=…, which posts the token here on load — no
 * login needed (the signed DigestUnsubscribeToken is the capability, analog
 * /login/verify). A GET never changes anything, so link scanners prefetching
 * the mail link cannot unsubscribe anybody.
 *
 * The token can only switch its own user's digest OFF — never on, never any
 * other preference. Invalid/forged tokens get one constant 400 (fail-secure).
 */
final readonly class DigestUnsubscribeAction
{
    public function __construct(
        private UserRepository $users,
        private DigestUnsubscribeToken $tokens,
        private AuditLogger $audit,
    ) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $parsed = $request->getParsedBody();
        $token  = is_array($parsed) && is_string($parsed['token'] ?? null) ? $parsed['token'] : '';
        $userId = $token !== '' ? $this->tokens->verify($token) : null;

        if ($userId === null) {
            $response->getBody()->write((string) json_encode([
                'error' => ['key' => 'invalid_token', 'message' => 'The link is invalid.'],
            ]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        $this->users->setWeeklyDigestEnabled($userId, false);
        $this->audit->log('user.digest_unsubscribed', ['user_id' => $userId]);

        $response->getBody()->write((string) json_encode(['ok' => true]));
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }
}
