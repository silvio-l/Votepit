<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Http\Middleware\AccountContextMiddleware;
use Votepit\Http\Middleware\SecurityHeaderMiddleware;
use Votepit\Http\Support\JsonError;
use Votepit\Http\Support\WidgetGate;
use Votepit\Logging\AuditLogger;
use Votepit\Persistence\BoardRepository;
use Votepit\Persistence\UserRepository;
use Votepit\Security\EncryptionService;
use Votepit\Security\IdentityHasher;
use Votepit\Security\WidgetJwtVerifier;
use Votepit\Security\WidgetTokenService;

/**
 * POST {account}/{board}/widget/identify — exchanges a customer-signed
 * identify JWT (`Authorization: Bearer <jwt>`) for a short-lived widget
 * token (ADR 0005 §3). There is NO unsigned identify: without a valid
 * HS256 signature under this board's widget secret nothing happens.
 *
 * The signed JWT is the capability (like a magic-link token), hence
 * AuthZMiddleware::anon() + a per-IP rate limit on the route; no session or
 * cookie is read or written (CSRF exemption by route name, see
 * CsrfMiddleware::WIDGET_ROUTE_NAME_PREFIX).
 *
 * Identity: the asserted e-mail is only ever used to compute an
 * account-separated HMAC (IdentityHasher::hashWidgetIdentity(), ADR 0002)
 * to find or create this account's widget user — never the global user
 * who signs in with that address, so a customer holding the widget secret
 * cannot act as a real platform user. The plaintext is never stored or
 * logged. `name`/`sub` are not
 * persisted either — `name` is echoed back for the widget header only. An
 * existing user's profile is never modified by a customer assertion.
 *
 * Every failure (unknown board, widget off, no secret, bad signature,
 * expired, wrong audience) is answered uniformly, 401/404 without detail.
 */
final readonly class WidgetIdentifyAction
{
    public function __construct(
        private BoardRepository $boards,
        private WidgetGate $gate,
        private EncryptionService $secretEncryption,
        private WidgetJwtVerifier $verifier,
        private WidgetTokenService $tokens,
        private IdentityHasher $hasher,
        private UserRepository $users,
        private AuditLogger $audit,
    ) {}

    /** @param array<string, mixed> $args */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $response  = $response->withHeader(SecurityHeaderMiddleware::INTERNAL_COOKIELESS, '1');
        $slug      = is_string($args['board'] ?? null) ? $args['board'] : '';
        $accountId = (int) $request->getAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID);
        $board     = $this->boards->findPublicBySlugForAccount($slug, $accountId);
        $config    = is_array($board) ? $this->gate->activeConfig($accountId, (int) $board['id']) : null;
        if (!is_array($board) || $config === null) {
            return JsonError::respond($response, 404, 'not_found', 'Board not found.');
        }
        $boardId = (int) $board['id'];

        $secret = $config['secret_encrypted'] !== null ? $this->secretEncryption->decrypt($config['secret_encrypted']) : null;
        $header = $request->getHeaderLine('Authorization');
        $now    = time();
        $claims = $secret !== null && str_starts_with($header, 'Bearer ')
            ? $this->verifier->verify(substr($header, 7), $secret, WidgetJwtVerifier::audienceFor($boardId), $now)
            : null;
        if ($claims === null || $config['secret_encrypted'] === null) {
            $this->audit->log('widget.identify_rejected', ['board_id' => $boardId]);
            return JsonError::respond($response, 401, 'invalid_token', 'The identify token is invalid or expired.');
        }

        $emailHmac = $this->hasher->hashWidgetIdentity($accountId, $claims['email']);
        $user      = $this->users->findByEmailHmac($emailHmac);
        $created   = false;
        if (!is_array($user)) {
            try {
                $user    = $this->users->create($emailHmac);
                $created = true;
            } catch (UniqueConstraintViolationException) {
                // Concurrent identify for the same new e-mail — the other
                // request created the row, use it.
                $user = $this->users->findByEmailHmac($emailHmac);
            }
        }
        if (!is_array($user)) {
            return JsonError::respond($response, 401, 'invalid_token', 'The identify token is invalid or expired.');
        }
        if ((bool) ($user['is_blocked'] ?? false)) {
            return JsonError::respond($response, 403, 'blocked', 'Account blocked.');
        }

        $userId = (int) $user['id'];
        $token  = $this->tokens->issue(
            $accountId,
            $boardId,
            $userId,
            WidgetTokenService::secretFingerprint($config['secret_encrypted'], $config['secret_rotated_at']),
            $now,
        );

        $this->audit->log('widget.identified', ['board_id' => $boardId, 'user_id' => $userId, 'created' => $created]);

        $response->getBody()->write((string) json_encode([
            'token'      => $token,
            'expires_in' => WidgetTokenService::TTL,
            'user'       => ['name' => $claims['name']],
        ]));

        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }
}
