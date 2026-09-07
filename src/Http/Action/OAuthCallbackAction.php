<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Doctrine\DBAL\Connection;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Config;
use Votepit\Logging\AuditLogger;
use Votepit\Persistence\LoginTokenRepository;
use Votepit\Persistence\OAuthIdentityRepository;
use Votepit\Persistence\OAuthStateRepository;
use Votepit\Persistence\UserRepository;
use Votepit\Security\IdentityHasher;
use Votepit\Security\LoginSessionIssuer;
use Votepit\Security\OAuth\OAuthHttpClient;
use Votepit\Security\OAuth\OAuthHttpException;
use Votepit\Security\OAuth\OAuthProviderRegistry;
use Votepit\Security\ReturnToValidator;
use Votepit\Security\TokenVault;

/**
 * GET /login/oauth/{provider}/callback — completes the OAuth2 flow for
 * `google`|`github` (AuthZ: anon).
 *
 * On ANY failure (missing/invalid/replayed `state`, provider `error`,
 * missing `code`, token-exchange failure, profile-fetch failure, no usable
 * verified email) this returns a GENERIC JSON error and logs a generic
 * `oauth.callback_failed` audit event — the provider's own error text is
 * NEVER included (no detail leak to the client or the log).
 *
 * `state` is consumed (marked used) by OAuthStateRepository::
 * consumeActiveByHash() on the FIRST read, before the token exchange even
 * runs — a replayed callback (same `state` twice, whether the first attempt
 * succeeded or failed) always fails from the second attempt on.
 *
 * Account linking (requirement: an existing magic-link/password user who
 * now also signs in via Google/GitHub must not get a duplicate account):
 * UserRepository::findByEmailHmac() ?? create() is reused VERBATIM (same
 * as POST /login and LoginPasswordAction) — "one email = one user" is
 * enforced there, not re-implemented here. oauth_identities only ever links
 * an ALREADY-resolved user_id to the provider's opaque subject id, and NEVER
 * stores an email in any form (ADR 0002).
 *
 * TOTP gate: mirrors LoginVerifyAction/LoginPasswordAction exactly — a
 * user with TOTP enabled gets a pending-2FA token instead of a session, so a
 * compromised OAuth account (or a leaked email at the provider) alone
 * cannot bypass 2FA.
 */
final readonly class OAuthCallbackAction
{
    /** TTL of the pending-2FA token — same as LoginPasswordAction/LoginVerifyAction. */
    private const PENDING_2FA_TTL_SECONDS = 300;

    public function __construct(
        private Config $config,
        private OAuthStateRepository $states,
        private OAuthIdentityRepository $identities,
        private UserRepository $userRepo,
        private LoginTokenRepository $tokenRepo,
        private TokenVault $vault,
        private IdentityHasher $hasher,
        private OAuthHttpClient $http,
        private LoginSessionIssuer $sessionIssuer,
        private AuditLogger $audit,
        private Connection $conn,
    ) {}

    /** @param array<string, mixed> $args */
    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $provider = is_string($args['provider'] ?? null) ? $args['provider'] : '';

        if (!OAuthProviderRegistry::isKnown($provider) || !$this->config->oauthProviderConfigured($provider)) {
            return $this->fail($response, 404, $provider, 'unknown_or_unconfigured_provider');
        }

        $params = $request->getQueryParams();

        // The provider itself reports a failure (e.g. the user denied consent).
        if (is_string($params['error'] ?? null) && $params['error'] !== '') {
            return $this->fail($response, 400, $provider, 'provider_error');
        }

        $state = is_string($params['state'] ?? null) ? $params['state'] : '';
        if ($state === '') {
            return $this->fail($response, 400, $provider, 'missing_state');
        }

        // Marks the state used HERE, on first read — a replay of this exact
        // callback (same state twice) fails from here on regardless of what
        // happens below.
        $stateRow = $this->states->consumeActiveByHash($this->vault->hash($state), $provider);
        if (!is_array($stateRow)) {
            return $this->fail($response, 400, $provider, 'invalid_state');
        }

        $code = is_string($params['code'] ?? null) ? $params['code'] : '';
        if ($code === '') {
            return $this->fail($response, 400, $provider, 'missing_code');
        }

        $endpoints = OAuthProviderRegistry::endpoints($provider);
        $creds     = $this->config->oauthProvider($provider);

        $tokenParams = [
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            // Hard-coded, identical to the one sent in OAuthStartAction —
            // never derived from request input.
            'redirect_uri'  => $this->config->appUrl . '/login/oauth/' . $provider . '/callback',
            'client_id'     => $creds['client_id'],
            'client_secret' => $creds['client_secret'],
        ];
        if (is_string($stateRow['code_verifier'] ?? null)) {
            $tokenParams['code_verifier'] = $stateRow['code_verifier'];
        }

        try {
            $tokenResponse = $this->http->postForm($endpoints['token'], $tokenParams);
        } catch (OAuthHttpException) {
            return $this->fail($response, 502, $provider, 'token_exchange_network_error');
        }

        $accessToken = is_string($tokenResponse['body']['access_token'] ?? null)
            ? $tokenResponse['body']['access_token']
            : null;
        if ($tokenResponse['status'] !== 200 || $accessToken === null) {
            return $this->fail($response, 400, $provider, 'token_exchange_failed');
        }

        $authHeader = ['Authorization: Bearer ' . $accessToken];

        try {
            $profileResponse = $this->http->getJson($endpoints['userinfo'], $authHeader);
        } catch (OAuthHttpException) {
            return $this->fail($response, 502, $provider, 'profile_fetch_network_error');
        }
        if ($profileResponse['status'] !== 200) {
            return $this->fail($response, 400, $provider, 'profile_fetch_failed');
        }

        $extracted = $provider === 'google'
            ? $this->extractGoogleIdentity($profileResponse['body'])
            : $this->extractGitHubIdentity($profileResponse['body'], $endpoints['emails'], $authHeader);

        if ($extracted === null) {
            return $this->fail($response, 400, $provider, 'no_verified_email');
        }
        [$providerUserId, $email] = $extracted;

        // Only the pseudonymized HMAC from here on (ADR 0002) — $email is
        // never stored, logged, or referenced again after this line.
        $emailHmac = $this->hasher->hash($email);

        $returnToRaw = is_string($stateRow['return_to'] ?? null) ? $stateRow['return_to'] : '/';
        $returnTo    = ReturnToValidator::isValid($returnToRaw) ? $returnToRaw : '/';

        $isNewLink = false;

        /** @var array<string, mixed> $user */
        $user = $this->conn->transactional(
            function () use ($provider, $providerUserId, $emailHmac, &$isNewLink): array {
                $existing = $this->identities->findByProviderUserId($provider, $providerUserId);

                if (is_array($existing)) {
                    $userId = (int) $existing['user_id'];
                } else {
                    // "One email = one user" (ADR 0001 §2c / POST /login) —
                    // reused verbatim: an existing magic-link/password user
                    // signing in via OAuth for the first time links onto
                    // their EXISTING row, never a duplicate.
                    $userRow = $this->userRepo->findByEmailHmac($emailHmac) ?? $this->userRepo->create($emailHmac);
                    $userId  = (int) $userRow['id'];
                    $this->identities->link($userId, $provider, $providerUserId);
                    $isNewLink = true;
                }

                // OAuth proves email ownership just as clicking a magic link
                // does (LoginVerifyAction) — same idempotent markVerified().
                $this->userRepo->markVerified($userId);

                $loaded = $this->userRepo->findByIdWithCredentials($userId);
                if (!is_array($loaded)) {
                    // Should not happen inside a transaction → abort fail-secure.
                    throw new \RuntimeException('oauth callback: user not found after link');
                }

                return $loaded;
            },
        );

        $userId = (int) $user['id'];

        $this->audit->log($isNewLink ? 'oauth.linked' : 'oauth.succeeded', ['provider' => $provider, 'uid' => $userId]);

        // 2FA gate — same pending-token handoff as LoginVerifyAction/
        // LoginPasswordAction: a compromised OAuth account alone must not
        // be enough to obtain a session once TOTP is enabled.
        if (is_string($user['totp_enabled_at'] ?? null)) {
            $this->tokenRepo->deleteOpenForUser($userId);
            $pair      = $this->vault->generate();
            $expiresAt = (new \DateTimeImmutable('+' . self::PENDING_2FA_TTL_SECONDS . ' seconds'))->format('Y-m-d H:i:s');
            $this->tokenRepo->insertPending($userId, $pair['hash'], $expiresAt);

            $this->audit->log('oauth.pending_2fa', ['provider' => $provider, 'uid' => $userId]);

            $response->getBody()->write((string) json_encode([
                'requires_2fa'  => true,
                'pending_token' => $pair['token'],
            ]));
            return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
        }

        return $this->sessionIssuer->issue($response, $userId, (int) ($user['token_version'] ?? 0), $returnTo);
    }

    /**
     * @param array<array-key, mixed> $profile
     * @return array{0: string, 1: string}|null [provider_user_id, email]
     */
    private function extractGoogleIdentity(array $profile): ?array
    {
        $sub   = $profile['sub'] ?? null;
        $email = $profile['email'] ?? null;
        // Google's email_verified can be a bool or the string "true"
        // depending on endpoint/library version — accept both, reject
        // everything else (fail-secure: an unverified provider email is
        // never trusted as identity proof).
        $verified = ($profile['email_verified'] ?? null) === true || ($profile['email_verified'] ?? null) === 'true';

        if (!is_string($sub) || $sub === '' || !is_string($email) || $email === '' || !$verified) {
            return null;
        }

        return [$sub, $email];
    }

    /**
     * @param array<array-key, mixed> $profile
     * @param list<string>         $authHeader
     * @return array{0: string, 1: string}|null [provider_user_id, email]
     */
    private function extractGitHubIdentity(array $profile, ?string $emailsUrl, array $authHeader): ?array
    {
        $rawId = $profile['id'] ?? null;
        if (!is_int($rawId) && !is_string($rawId)) {
            return null;
        }
        $providerUserId = (string) $rawId;

        // GitHub's /user.email can be null (private) even for a verified
        // account — the primary verified address is fetched from the
        // dedicated /user/emails endpoint instead (requires the user:email scope).
        if ($emailsUrl === null) {
            return null;
        }

        try {
            $emailsResponse = $this->http->getJson($emailsUrl, $authHeader);
        } catch (OAuthHttpException) {
            return null;
        }
        if ($emailsResponse['status'] !== 200) {
            return null;
        }

        foreach ($emailsResponse['body'] as $entry) {
            if (
                is_array($entry)
                && ($entry['primary'] ?? false) === true
                && ($entry['verified'] ?? false) === true
                && is_string($entry['email'] ?? null)
                && $entry['email'] !== ''
            ) {
                return [$providerUserId, $entry['email']];
            }
        }

        return null;
    }

    private function fail(ResponseInterface $response, int $status, string $provider, string $reason): ResponseInterface
    {
        $this->audit->log('oauth.callback_failed', ['provider' => $provider, 'reason' => $reason]);

        $response->getBody()->write((string) json_encode([
            'error' => ['key' => 'oauth_failed', 'message' => 'The OAuth login could not be completed.'],
        ]));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
