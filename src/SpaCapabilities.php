<?php

declare(strict_types=1);

namespace Votepit;

/**
 * Static, hand-maintained record of what the SPA bundled with THIS core/
 * version actually implements client-side — checked by public/index.php's
 * boot-time self-check against the active config, so a routing_mode the
 * SPA cannot serve fails loudly at request time instead of surfacing as
 * scattered 404s discovered only via error monitoring (real incident,
 * 2026-08-31: a hosted instance ran routing_mode: 'cloud' while App.tsx had
 * zero {account}-prefixed routes — every account-scoped page 404'd).
 */
final class SpaCapabilities
{
    /**
     * True since core/app/src/App.tsx gained {account}-prefixed client-side
     * routes for every account-/board-scoped page (cloud path routing,
     * SPA half: accountContext.ts + App.tsx's ScopedLayout/
     * GlobalLayout split). The backend's cloud-mode routing itself was
     * already correct and fully tested before this — see
     * tests/Http/CloudRoutingTest.php. (Declared as a method with an
     * explicit `bool` return type, not a `const` — PHPStan would otherwise
     * narrow a literal const to always-true/-false at every call site and
     * flag the checks below as dead code, which defeats the point of a flag
     * meant to change.)
     */
    public static function cloudAccountRoutingReady(): bool
    {
        return true;
    }

    /**
     * Pure check, deliberately NOT inside Config::fromArray (which
     * integration tests also construct to exercise the backend's cloud-mode
     * routing directly, e.g. tests/Http/CloudRoutingTest.php — that must
     * keep working). Only the real front controller (public/index.php)
     * calls this. Returns an operator-facing error message if the
     * configured routing_mode can't actually be served, or null if fine.
     */
    public static function checkRoutingMode(Config $config): ?string
    {
        if ($config->routingMode === 'cloud' && !self::cloudAccountRoutingReady()) {
            return "Votepit: config \"routing_mode\" is set to \"cloud\", but the SPA frontend built "
                . "with this core version has no {account}-prefixed routes yet "
                . "(cloud multi-tenant routing is not built yet). Every "
                . "account-scoped page (e.g. /admin/boards) would only respond with 404. "
                . "Set \"routing_mode\" to \"self-host\" — this is also correct for a cloud installation "
                . "with (for now) only a single account.\n";
        }

        return null;
    }

    private function __construct() {}
}
