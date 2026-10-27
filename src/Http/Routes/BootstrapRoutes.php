<?php

declare(strict_types=1);

namespace Votepit\Http\Routes;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Psr7\Factory\ResponseFactory;
use Votepit\Config;
use Votepit\Domain\AccountPlanResolver;
use Votepit\Domain\Entitlements;
use Votepit\Domain\PlanPolicy;
use Votepit\Domain\PlatformRoles;
use Votepit\Http\Middleware\AuthNMiddleware;
use Votepit\Http\Middleware\AuthZMiddleware;
use Votepit\Http\Middleware\CsrfMiddleware;
use Votepit\Persistence\AccountMemberRepository;
use Votepit\Persistence\AccountRepository;
use Votepit\Persistence\UserRepository;

/**
 * GET /api/bootstrap — CSRF token, whoami and feature flags the SPA
 * loads once at startup.
 */
final class BootstrapRoutes
{
    /**
     * SPA bootstrap (CSRF token + whoami + feature flags).
     *
     * @param App<null> $app
     * @param array<string, bool|int|string|null> $bootstrapFeatures
     */
    public static function register(
        App $app,
        Config $config,
        ResponseFactory $responseFactory,
        UserRepository $userRepo,
        AccountMemberRepository $accountMemberRepo,
        AccountRepository $accountRepoForPlan,
        PlanPolicy $planPolicy,
        array $bootstrapFeatures,
    ): void {
        // GET /api/bootstrap — CSRF token + whoami for the SPA (AuthZ: anon).
        // Returns the current CSRF token and the logged-in user to the SPA.
        // Must be called by the SPA at startup, before any mutating requests are sent.
        $accountPlans = new AccountPlanResolver($accountRepoForPlan, $accountMemberRepo, $planPolicy);

        $app->get('/api/bootstrap', function (
            ServerRequestInterface $request,
            ResponseInterface $response,
        ) use ($config, $accountMemberRepo, $userRepo, $bootstrapFeatures, $accountRepoForPlan, $planPolicy, $accountPlans): ResponseInterface {
            $csrfToken = $request->getAttribute(CsrfMiddleware::ATTR_TOKEN);
            $user      = $request->getAttribute(AuthNMiddleware::ATTR_USER);
            $userPayload = null;
            if (is_array($user)) {
                $userId = (int) ($user['id'] ?? 0);

                // Only the boolean flags, never password_hash/totp_secret_encrypted
                // themselves — the SPA needs this exclusively to
                // render the profile security section correctly (password
                // already set? 2FA already active?).
                $complianceFrozenAccounts = $accountMemberRepo->ownedAccountsWithComplianceFrozenBoards($userId);
                $credentials  = $userRepo->findByIdWithCredentials($userId);
                $hasPassword  = is_array($credentials) && is_string($credentials['password_hash'] ?? null);
                $totpEnabled  = is_array($credentials) && is_string($credentials['totp_enabled_at'] ?? null);
                $avatarFilename = is_array($credentials) && is_string($credentials['avatar_filename'] ?? null)
                    ? $credentials['avatar_filename']
                    : null;

                $userPayload = [
                    'id'           => $userId,
                    // Random opaque handle (migrations/0036-0038), safe to
                    // display — the raw auto-increment id above stays
                    // internal-only (used as an API key for scoped
                    // mutations the caller is already authorized for,
                    // never rendered as text).
                    'public_id'    => is_array($credentials) && is_string($credentials['public_id'] ?? null)
                        ? $credentials['public_id']
                        : null,
                    // Optional, globally unique display name (migration
                    // 0022) — the SPA's session/account menu prefers this
                    // over public_id/account-slug wherever it needs to
                    // show "who is signed in" independent of which
                    // account/page is currently open.
                    'username'     => is_array($credentials) && is_string($credentials['username'] ?? null)
                        ? $credentials['username']
                        : null,
                    'is_admin'     => (bool) ($user['is_admin'] ?? false),
                    'is_operator'  => PlatformRoles::isOperator($user),
                    'is_support'   => (bool) ($user['is_support'] ?? false),
                    // Test-User feature: dedicated QA/E2E account, never a
                    // real customer — the SPA uses this to skip Matomo
                    // analytics entirely (App.tsx), server-side this flag
                    // also exempts the request from rate limiting (see
                    // RateLimitMiddleware::process()).
                    'is_test_account' => (bool) ($user['is_test_account'] ?? false),
                    'has_password' => $hasPassword,
                    'totp_enabled' => $totpEnabled,
                    // profile-avatar-social: piggybacked on the same
                    // per-user row already fetched above for
                    // has_password/totp_enabled, so the header's account
                    // menu (rendered on every page from bootstrap()) can
                    // show the real avatar without a second round-trip.
                    'avatar_url'   => $avatarFilename !== null ? '/avatar/' . $avatarFilename : null,
                    // profile-visibility feature: own current privacy
                    // setting, so ProfilePage's Privacy toggle renders
                    // correctly without an extra round-trip. Default is
                    // anonymous (migration 0021).
                    'profile_visible' => is_array($credentials) && (bool) ($credentials['profile_visible'] ?? false),
                    // Account role per slug (owner|moderator), NOT the
                    // platform admin flag above — the SPA uses this to
                    // correctly gate the admin UI for the current account
                    // slug (previously incorrectly used is_admin). Each row
                    // also carries that account's `entitlements` (P-38): the
                    // viewer's OWN accounts only, so no foreign plan leaks.
                    'memberships' => array_map(
                        static fn (array $m): array => [
                            'account_slug' => $m['account_slug'],
                            'role'         => $m['role'],
                            'entitlements' => Entitlements::forAccount(
                                $planPolicy,
                                $m['account_plan'],
                                $accountPlans->forAccount($m['account_id'], $user),
                                $m['role'],
                                ($bootstrapFeatures['billing'] ?? null) === true,
                            ),
                        ],
                        $accountMemberRepo->membershipsWithPlanFor($userId),
                    ),
                    // Board-operator-compliance follow-up: powers the header
                    // bell's warning badge (LocalizedHeader.tsx) — see
                    // AccountMemberRepository::ownedAccountsWithComplianceFrozenBoards()
                    // class doc. Deliberately summed across every account
                    // this user owns (almost always 0 or 1 — "one owned
                    // account per signup", SignupAccountAction), and always
                    // links to the first affected one.
                    'compliance_frozen_board_count' => array_sum(array_map(
                        static fn (array $a): int => $a['frozen_board_count'],
                        $complianceFrozenAccounts,
                    )),
                    'compliance_frozen_account_slug' => $complianceFrozenAccounts[0]['account_slug'] ?? null,
                ];
            }

            $response->getBody()->write((string) json_encode([
                'csrf_token'            => is_string($csrfToken) ? $csrfToken : '',
                'user'                  => $userPayload,
                // Tells the SPA whether to expect an /{account}-prefixed path
                // segment on board-/admin-scoped routes (Config::routingMode,
                // cloud path routing) — see AccountContextMiddleware
                // and $accountPrefix in AppFactory::create(). Not sensitive: same value for every
                // visitor of this installation.
                'routing_mode'          => $config->routingMode,
                // Client-facing DSN for @sentry/react (main.tsx) — '' disables
                // it (self-host default). Not sensitive: a Sentry DSN only
                // authorizes sending events, same value for every visitor.
                'sentry_dsn_frontend'   => $config->sentryDsnFrontend,
                // This installation's OWN optional analytics (Config
                // doc comment) — '' disables it, same "public,
                // authorizes sending only" reasoning as the Sentry DSN
                // above.
                'matomo_url'            => $config->matomoUrl,
                'matomo_site_id'        => $config->matomoSiteId,
                // Product-improvement telemetry (self-host only, see
                // Votepit\Telemetry\CommunityTelemetry) — null in cloud
                // mode, the SPA never renders the consent step there.
                // matomo_site_id stays '' (inert) until both the
                // account opted in AND CommunityTelemetry::MATOMO_SITE_ID
                // has been filled in.
                'telemetry'             => $config->routingMode === 'self-host'
                    ? (function () use ($accountRepoForPlan): array {
                        $account  = $accountRepoForPlan->findById($accountRepoForPlan->defaultAccountId());
                        $optedIn  = is_array($account) && (bool) ($account['telemetry_opted_in'] ?? false);
                        $decided  = is_array($account) && $account['telemetry_decided_at'] !== null;
                        return [
                            'decided'        => $decided,
                            'opted_in'       => $optedIn,
                            'matomo_url'     => $optedIn ? \Votepit\Telemetry\CommunityTelemetry::MATOMO_URL : '',
                            'matomo_site_id' => $optedIn ? \Votepit\Telemetry\CommunityTelemetry::MATOMO_SITE_ID : '',
                        ];
                    })()
                    : null,
                // Edition/extension feature flags (see $bootstrapFeatures in
                // AppFactory::create()). Not sensitive: same value for every visitor.
                'features'              => $bootstrapFeatures,
                // OAuth login (Google/GitHub) — only providers with BOTH
                // client_id/client_secret configured (Config::
                // oauthProviderConfigured()) appear here, so the SPA simply
                // renders no button for an unconfigured provider instead of
                // a broken one (LoginPage.tsx). Not sensitive: same value
                // for every visitor, no secret is ever exposed.
                'oauth_providers'       => $config->configuredOAuthProviders(),
                // idea-comment-translation: whether the "Translate" affordance
                // should render at all — Config::translationConfigured() is
                // fail-secure (false unless a provider is fully configured),
                // so the SPA simply renders no button when this is false
                // instead of a click-through to a 503. Not sensitive: same
                // value for every visitor, no credentials are ever exposed.
                'translation_available' => $config->translationConfigured(),
            ]));
            return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
        })->add(AuthZMiddleware::anon($responseFactory));
    }
}
