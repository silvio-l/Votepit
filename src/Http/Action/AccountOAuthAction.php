<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Http\Middleware\AuthNMiddleware;
use Votepit\Logging\AuditLogger;
use Votepit\Persistence\OAuthIdentityRepository;
use Votepit\Security\OAuth\OAuthProviderRegistry;

/**
 * GET    /account/oauth-identities           — the caller's own linked OAuth providers (AuthZ: user).
 * DELETE /account/oauth-identities/{provider} — unlinks one provider (AuthZ: user, CSRF).
 *
 * User-scoped (NOT account-scoped), same reasoning as AccountProfileAction:
 * a linked-providers list is the same across every account the user
 * belongs to. Unlinking never risks locking anyone out — magic-link login
 * (POST /login) works for every user regardless of OAuth linkage, since
 * every user row always carries the email HMAC used to resolve it
 * (ADR 0002) — no "last remaining method" guard is needed here.
 */
final readonly class AccountOAuthAction
{
    public function __construct(
        private OAuthIdentityRepository $identities,
        private AuditLogger $audit,
    ) {}

    /** GET /account/oauth-identities */
    public function list(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $userId = $this->currentUserId($request);
        $linked = $this->identities->findAllByUserId($userId);

        $response->getBody()->write((string) json_encode([
            'identities' => array_map(
                static fn (array $row): array => [
                    'provider'   => $row['provider'],
                    'created_at' => $row['created_at'],
                ],
                $linked,
            ),
        ]));
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }

    /**
     * DELETE /account/oauth-identities/{provider} — idempotent: unlinking a
     * provider that isn't currently linked still returns ok:true, mirroring
     * AccountProfileAction::deleteAvatar's convention.
     *
     * @param array<string, mixed> $args
     */
    public function unlink(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $provider = is_string($args['provider'] ?? null) ? $args['provider'] : '';

        if (!OAuthProviderRegistry::isKnown($provider)) {
            return $this->errorResponse($response, 404, 'unknown_provider', 'Unknown OAuth provider.');
        }

        $userId  = $this->currentUserId($request);
        $removed = $this->identities->unlink($userId, $provider);

        if ($removed) {
            $this->audit->log('user.oauth_unlinked', ['user_id' => $userId, 'provider' => $provider]);
        }

        $response->getBody()->write((string) json_encode(['ok' => true]));
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }

    private function currentUserId(ServerRequestInterface $request): int
    {
        /** @var array<string, mixed>|null $user */
        $user = $request->getAttribute(AuthNMiddleware::ATTR_USER);
        return is_array($user) ? (int) ($user['id'] ?? 0) : 0;
    }

    private function errorResponse(ResponseInterface $response, int $status, string $key, string $message): ResponseInterface
    {
        $response->getBody()->write((string) json_encode([
            'error' => ['key' => $key, 'message' => $message],
        ]));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
