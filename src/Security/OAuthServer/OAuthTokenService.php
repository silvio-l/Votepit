<?php

declare(strict_types=1);

namespace Votepit\Security\OAuthServer;

use Doctrine\DBAL\Connection;
use Votepit\Logging\AuditLogger;
use Votepit\Persistence\ApiTokenRepository;
use Votepit\Persistence\OAuthAuthorizationCodeRepository;
use Votepit\Persistence\OAuthTokenRepository;
use Votepit\Security\TokenVault;
use Votepit\Support\Clock;

/**
 * Token endpoint + revocation logic of the MCP OAuth server (issue 21).
 *
 * The grant is an api_tokens row (oauth_client_id set, label = client name,
 * created_by_user_id = the consenting user, api_token_boards = the consented
 * board levels). Its own token_hash is the hash of a random secret that is
 * discarded on creation, so the row can never be used as a static token;
 * it authenticates only through the access tokens issued here. That is what
 * makes OAuth "the same authorization path": scoping, rate limits, write
 * budgets, the moderate grant's issuer-role check, revocation on the token
 * page and plan-downgrade revocation all act on this one row.
 *
 * Security properties (each covered by tests):
 *  - codes and tokens are stored only as SHA-256 hashes;
 *  - a code is single-use (atomic claim); replaying a consumed code revokes
 *    the grant it produced (RFC 6749 §4.1.2);
 *  - PKCE S256 is verified in constant time; any mismatch (verifier,
 *    redirect_uri, client, expiry) burns the code;
 *  - refresh tokens rotate on every use; presenting an already rotated one
 *    (or losing the rotation race) revokes the whole family (OAuth 2.1
 *    §4.3.1 / BCP 9700 §4.14.2);
 *  - the consenting user's right to delegate (OAuthGrantEligibility) is
 *    re-checked at exchange and on every refresh — losing it revokes.
 *
 * Results are arrays: either the RFC 6749 §5.1 success body or
 * ['error' => ..., 'error_description' => ...].
 */
final readonly class OAuthTokenService
{
    public function __construct(
        private Connection $conn,
        private ApiTokenRepository $grants,
        private OAuthAuthorizationCodeRepository $codes,
        private OAuthTokenRepository $tokens,
        private OAuthGrantEligibility $eligibility,
        private TokenVault $vault,
        private Clock $clock,
        private AuditLogger $audit,
    ) {}

    /**
     * @param array{id: int, client_id: string, client_name: string, redirect_uris: list<string>} $client
     * @return array<string, mixed>
     */
    public function exchangeCode(array $client, string $code, string $verifier, string $redirectUri): array
    {
        $now = $this->clock->now();
        $row = str_starts_with($code, OAuthTokens::CODE_PREFIX) ? $this->codes->findByHash($this->vault->hash($code)) : null;

        if ($row === null) {
            return $this->invalidGrant('The authorization code is invalid.');
        }

        if ($row['consumed_at'] !== null || !$this->codes->consume($row['id'], $now)) {
            // Replay: the code leaked or the exchange is attacked — kill what it produced.
            if ($row['api_token_id'] !== null) {
                $this->grants->revokeOAuthGrant($row['api_token_id']);
            }
            $this->audit->log('oauth.code_reuse_detected', [
                'account_id'      => $row['account_id'],
                'oauth_client_id' => $row['oauth_client_id'],
                'token_id'        => $row['api_token_id'],
            ]);

            return $this->invalidGrant('The authorization code is invalid.');
        }

        // From here on the code is burned, whatever the outcome.
        if (
            $row['oauth_client_id'] !== $client['id']
            || $row['expires_at'] <= OAuthTokens::format($now)
            || !hash_equals($row['redirect_uri'], $redirectUri)
            || !PkceVerifier::isValidVerifier($verifier)
            || !PkceVerifier::verify($verifier, $row['code_challenge'])
        ) {
            return $this->invalidGrant('The authorization code is invalid.');
        }

        if ($row['board_scopes'] === [] || $this->eligibility->denialReason($row['account_id'], $row['user_id']) !== null) {
            return $this->invalidGrant('The authorizing user can no longer grant access to this account.');
        }

        $boardScopes = $row['board_scopes'];
        $grantSecret = OAuthTokens::generate('vpgrant_', $this->vault);

        $issued = $this->conn->transactional(function () use ($row, $client, $boardScopes, $grantSecret, $now): array {
            $grantId = $this->grants->create(
                $row['account_id'],
                $boardScopes,
                $row['user_id'],
                $client['client_name'],
                $grantSecret['hash'],
                $client['id'],
            );
            $this->codes->attachGrant($row['id'], $grantId);

            return ['grant_id' => $grantId] + $this->issuePair($grantId, $now);
        });

        $this->audit->log('oauth.grant_issued', [
            'account_id'      => $row['account_id'],
            'actor_id'        => $row['user_id'],
            'oauth_client_id' => $client['id'],
            'token_id'        => $issued['grant_id'],
        ]);

        return $this->success($issued['access'], $issued['refresh'], $boardScopes);
    }

    /**
     * @param array{id: int, client_id: string, client_name: string, redirect_uris: list<string>} $client
     * @return array<string, mixed>
     */
    public function refresh(array $client, string $refreshToken): array
    {
        $now = $this->clock->now();
        $this->tokens->purgeExpired($now);

        $token = str_starts_with($refreshToken, OAuthTokens::REFRESH_PREFIX)
            ? $this->tokens->findByHash($this->vault->hash($refreshToken))
            : null;
        $grant = $token !== null && $token['kind'] === OAuthTokenRepository::KIND_REFRESH
            ? $this->grants->findOAuthGrant($token['api_token_id'])
            : null;

        if ($token === null || $grant === null || $grant['revoked_at'] !== null || $token['expires_at'] <= OAuthTokens::format($now)) {
            return $this->invalidGrant('The refresh token is invalid.');
        }

        if ($grant['oauth_client_id'] !== $client['id'] || $token['rotated_at'] !== null || !$this->tokens->markRotated($token['id'], $now)) {
            // A rotated (or foreign-client) refresh token being presented means
            // two parties hold the family — revoke it entirely.
            $this->grants->revokeOAuthGrant($grant['id']);
            $this->audit->log('oauth.refresh_reuse_detected', [
                'account_id'      => $grant['account_id'],
                'oauth_client_id' => $grant['oauth_client_id'],
                'token_id'        => $grant['id'],
            ]);

            return $this->invalidGrant('The refresh token is invalid.');
        }

        if ($grant['board_scopes'] === [] || $this->eligibility->denialReason($grant['account_id'], $grant['created_by_user_id']) !== null) {
            $this->grants->revokeOAuthGrant($grant['id']);
            $this->audit->log('oauth.grant_revoked_ineligible', [
                'account_id' => $grant['account_id'],
                'token_id'   => $grant['id'],
            ]);

            return $this->invalidGrant('The authorizing user can no longer grant access to this account.');
        }

        $issued = $this->conn->transactional(fn (): array => $this->issuePair($grant['id'], $now));

        return $this->success($issued['access'], $issued['refresh'], $grant['board_scopes']);
    }

    /**
     * RFC 7009. Unknown tokens, tokens of another client and already
     * revoked ones are a silent no-op (the endpoint answers 200 either way,
     * so it is no oracle for token validity). A refresh token revokes the
     * whole grant; an access token only itself.
     *
     * @param array{id: int, client_id: string, client_name: string, redirect_uris: list<string>} $client
     */
    public function revoke(array $client, string $token): void
    {
        $isOAuthToken = str_starts_with($token, OAuthTokens::ACCESS_PREFIX) || str_starts_with($token, OAuthTokens::REFRESH_PREFIX);
        $row          = $isOAuthToken ? $this->tokens->findByHash($this->vault->hash($token)) : null;
        $grant        = $row !== null ? $this->grants->findOAuthGrant($row['api_token_id']) : null;

        if ($row === null || $grant === null || $grant['oauth_client_id'] !== $client['id']) {
            return;
        }

        if ($row['kind'] === OAuthTokenRepository::KIND_REFRESH) {
            $this->grants->revokeOAuthGrant($grant['id']);
            $this->audit->log('oauth.grant_revoked_by_client', [
                'account_id'      => $grant['account_id'],
                'oauth_client_id' => $client['id'],
                'token_id'        => $grant['id'],
            ]);

            return;
        }

        $this->tokens->delete($row['id']);
    }

    /** @return array{access: string, refresh: string} */
    private function issuePair(int $grantId, \DateTimeImmutable $now): array
    {
        $access  = OAuthTokens::generate(OAuthTokens::ACCESS_PREFIX, $this->vault);
        $refresh = OAuthTokens::generate(OAuthTokens::REFRESH_PREFIX, $this->vault);

        $this->tokens->store($grantId, OAuthTokenRepository::KIND_ACCESS, $access['hash'], $now->modify('+' . OAuthTokens::ACCESS_TTL_SECONDS . ' seconds'), $now);
        $this->tokens->store($grantId, OAuthTokenRepository::KIND_REFRESH, $refresh['hash'], $now->modify('+' . OAuthTokens::REFRESH_TTL_SECONDS . ' seconds'), $now);

        return ['access' => $access['token'], 'refresh' => $refresh['token']];
    }

    /**
     * @param array<int, string> $boardScopes
     * @return array<string, mixed>
     */
    private function success(string $access, string $refresh, array $boardScopes): array
    {
        return [
            'access_token'  => $access,
            'token_type'    => 'Bearer',
            'expires_in'    => OAuthTokens::ACCESS_TTL_SECONDS,
            'refresh_token' => $refresh,
            'scope'         => OAuthScope::grantedScope($boardScopes),
        ];
    }

    /** @return array{error: string, error_description: string} */
    private function invalidGrant(string $description): array
    {
        return ['error' => 'invalid_grant', 'error_description' => $description];
    }
}
