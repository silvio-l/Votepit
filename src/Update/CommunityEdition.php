<?php

declare(strict_types=1);

namespace Votepit\Update;

use Votepit\Config;
use Votepit\Extension\ExtensionLoader;

/**
 * Fixed endpoints and identifiers of the Community update channel.
 *
 * Hardcoded on purpose: no config.php key can point the update check or the
 * version report at another host, so the two outbound calls are exactly the
 * ones documented in documentation/installation.md ("What your installation
 * sends"). Changing one of these is a documented, reviewed change, not a
 * configuration option.
 */
final class CommunityEdition
{
    public const GITHUB_REPOSITORY = 'silvio-l/Votepit';

    public const LATEST_RELEASE_URL = 'https://api.github.com/repos/silvio-l/Votepit/releases/latest';

    /** @var non-empty-string sprintf pattern, argument: tag name such as v0.1.8 */
    public const RELEASE_BY_TAG_URL = 'https://api.github.com/repos/silvio-l/Votepit/releases/tags/%s';

    /** @var non-empty-string sprintf pattern, argument: tag name such as v0.1.8 */
    public const RELEASE_PAGE_URL = 'https://github.com/silvio-l/Votepit/releases/tag/%s';

    public const UPGRADE_DOCS_URL = 'https://github.com/silvio-l/Votepit/blob/main/documentation/installation.md#upgrading-an-existing-installation';

    /** Votepit's own aggregate Matomo instance (Matomo site "Votepit Community (aggregated)"). */
    public const VERSION_REPORT_URL = 'https://matomo.silvio-und-maik.de/matomo.php'; // export-ok: own-matomo-host; export-ok: operator-account; export-ok: comment-language

    public const VERSION_REPORT_SITE_ID = 11;

    /** Fixed page URL sent with the report. Never the operator's own domain. */
    public const VERSION_REPORT_PAGE = 'https://github.com/silvio-l/Votepit';

    public const HTTP_TIMEOUT_SECONDS = 5;

    public const DOWNLOAD_TIMEOUT_SECONDS = 600;

    /** Upper bound for a release download; a Community archive is a few megabytes. */
    public const MAX_DOWNLOAD_BYTES = 64 * 1024 * 1024;

    /**
     * True only on a real Community installation: routing_mode 'self-host' and
     * no configured extension that reports another product edition. The public
     * demo runs 'self-host' but reports product_edition 'cloud', so neither the
     * update check nor the version report ever runs there.
     *
     * @throws \Votepit\ConfigException when an extension cannot be loaded
     */
    public static function runsUpdateChannel(Config $config): bool
    {
        if ($config->routingMode !== 'self-host') {
            return false;
        }

        $features = ExtensionLoader::bootstrapFeatures(ExtensionLoader::fromConfig($config), []);

        return ($features['product_edition'] ?? 'community') === 'community';
    }

    /** Release assets the updater needs, per version (see tools/release-community.sh). */
    public static function archiveName(string $version): string
    {
        return 'votepit-community-' . $version . '.zip';
    }
}
