<?php

declare(strict_types=1);

namespace Votepit\Extension;

use Psr\Http\Server\MiddlewareInterface;
use Votepit\Domain\PlanPolicy;
use Votepit\Http\CoreRoute;

/**
 * Extension point for code that is NOT part of Votepit Community Edition.
 *
 * The Community Edition is complete on its own: it ships no billing, no
 * paid tiers and no hosted-service specifics. An operator who runs Votepit
 * as a hosted service can plug such concerns in through this interface
 * without forking core — the extension lives in its own package, is
 * autoloaded by config/config.php and is declared under the `extensions`
 * config key:
 *
 *     'extensions' => [
 *         \Vendor\Package\SomeExtension::class => [ ...options passed to fromOptions()... ],
 *     ],
 *
 * Core never references a concrete extension class. Everything an
 * extension can influence is enumerated by this interface; anything not
 * listed here is deliberately out of reach.
 *
 * Every method except register() and routeMiddleware() is consulted once
 * while AppFactory wires the application; register() is invoked at the
 * point where global routes may still be added before the first
 * account-prefixed route (see the "Cloud-Routing safety net" comments in
 * AppFactory); routeMiddleware() is consulted last, once every core route
 * exists.
 */
interface AppExtension
{
    /**
     * Builds the extension from its config options (the array configured
     * under the extension's class name). Must fail fast (throw) on invalid
     * options — a misconfigured extension must never boot half-way.
     *
     * @param array<string, mixed> $options
     */
    public static function fromOptions(array $options): self;

    /**
     * Plan policy replacing the Community default (UnrestrictedPlanPolicy),
     * or null to keep the default. At most ONE registered extension may
     * return a non-null policy; AppFactory rejects the configuration
     * otherwise.
     */
    public function planPolicy(): ?PlanPolicy;

    /**
     * CSRF exemptions for machine-to-machine POST endpoints that
     * authenticate through a request header the extension verifies itself
     * (e.g. a payment provider's webhook signature). Exact request path =>
     * header name; the exemption applies only when that header is present
     * on exactly that path. The extension MUST register a middleware on the
     * route that rejects an invalid/missing header — CsrfMiddleware defers
     * to it, it does not verify anything itself.
     *
     * @return array<string, string>
     */
    public function csrfExemptions(): array;

    /**
     * Hook run before an owner-requested account deletion is scheduled
     * (AccountDeleteAction). null keeps the core default (no precondition).
     * Receives the same context as register() so the precondition can reach
     * the database/audit log (e.g. to cancel an external subscription first).
     */
    public function accountDeletionPrecondition(ExtensionContext $ctx): ?AccountDeletionPrecondition;

    /**
     * Feature flags/data merged into the `features` object of
     * GET /api/bootstrap so the SPA can adapt (e.g. show a billing page).
     * Keys are merged over core's own features; values must be
     * JSON-encodable and must not contain secrets — the payload is public.
     *
     * @return array<string, mixed>
     */
    public function bootstrapFeatures(): array;

    /**
     * Static response headers set on EVERY response (header name => value),
     * e.g. a crawler directive for an installation that must not be
     * indexed. Applied by SecurityHeaderMiddleware alongside core's own
     * security headers; the names core sets itself (CSP, HSTS, …) are
     * reserved and rejected at boot, as is the same header declared by two
     * extensions — an extension can add to the security posture, never
     * weaken it. Return [] for none.
     *
     * @return array<string, string>
     */
    public function responseHeaders(): array;

    /**
     * Middleware to attach to core-owned routes, keyed by CoreRoute name.
     * Only the routes enumerated in CoreRoute can be targeted; an unknown
     * name, or a name whose route is not registered in this installation
     * (e.g. CoreRoute::BOARD_SMTP_TEST in routing_mode 'cloud'), aborts the
     * boot. The middleware is attached OUTERMOST on the route (outside AuthZ
     * and the per-action rate limit), so it may either short-circuit the
     * request with its own response or observe core's response on the way
     * out. Receives the same context as register(). Return [] for none.
     *
     * @return array<string, list<MiddlewareInterface>>
     */
    public function routeMiddleware(ExtensionContext $ctx): array;

    /** Registers routes/middleware. See ExtensionContext for what is available. */
    public function register(ExtensionContext $ctx): void;
}
