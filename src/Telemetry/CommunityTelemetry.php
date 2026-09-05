<?php

declare(strict_types=1);

namespace Votepit\Telemetry;

/**
 * Fixed target for Community Edition product-improvement telemetry —
 * deliberately NOT read from config.php (see Config's doc comment). Gated
 * exclusively by the per-account `telemetry_opted_in` consent flag; empty
 * MATOMO_SITE_ID keeps the whole path inert (bootstrap never exposes a
 * usable tracker config, SPA never loads the script) until the "Votepit
 * Community (aggregated)" Matomo site has been created and its ID filled in
 * here. Cookieless + IP-anonymized, matching the Landingpage/`web/` pattern
 * (cookieless + anonymized Matomo needs no consent banner — but this is a
 * THIRD PARTY receiving data from someone else's installation, so explicit
 * opt-in still applies regardless of the cookie question).
 */
final class CommunityTelemetry
{
    public const MATOMO_URL = 'https://matomo.silvio-und-maik.de'; // export-ok: comment-language

    public const MATOMO_SITE_ID = '11';
}
