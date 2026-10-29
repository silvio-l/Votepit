<?php

declare(strict_types=1);

namespace Votepit\Http\Support;

use Doctrine\DBAL\Connection;
use Votepit\Config;
use Votepit\Domain\AccountPlanResolver;
use Votepit\Domain\PlanPolicy;
use Votepit\Domain\UnrestrictedPlanPolicy;
use Votepit\Extension\ExtensionLoader;
use Votepit\Persistence\AccountMemberRepository;
use Votepit\Persistence\AccountRepository;
use Votepit\Persistence\BoardAssetRepository;
use Votepit\Persistence\BoardRepository;
use Votepit\Persistence\ConnectionFactory;
use Votepit\Persistence\IdeaRepository;
use Votepit\Security\BoardAssetProcessor;
use Votepit\Security\SlugValidator;

/**
 * Per-board meta for the SPA shell (P-28: favicon + og/twitter share image).
 *
 * The SPA is client-rendered, so crawlers and link previews only see the static
 * shell. For a PUBLIC board's home, roadmap, submit, impressum, idea and idea-edit
 * page, this injects the board's name, its favicon and its share image into the
 * <head> of that one response, so a browser tab shows the board's icon everywhere.
 *
 * Member profile (owner 27b): a profile reached from a board (`?board=` context)
 * takes that board's favicon and share image too, but not its title. The board
 * is looked up in the account of the path, under the same public-only rule, so a
 * foreign, private, frozen or unknown board slug adds nothing and the plain shell
 * stays.
 *
 * Rules:
 * - Only boards a non-member can already read (findPublicBySlugForAccount).
 *   Private, unlisted-by-plan or compliance-frozen boards get the plain shell.
 * - Every value is escaped for an HTML attribute or text node (htmlspecialchars,
 *   ENT_QUOTES). No tenant-provided markup or script is ever emitted.
 * - Image URLs point at the gated public asset endpoint; the plan gate is
 *   enforced there, so a downgraded board's tags point at a 404 and nothing is
 *   served. Files are never read here.
 * - Fail-open to the plain shell: any error (config, database, unknown path)
 *   returns the unmodified shell, never an error page.
 *
 * Indexing (Search Console, 2026-10-25: every route served the same shell, so
 * Google clustered them as duplicates without a canonical):
 * - A public board's home, roadmap, impressum and idea pages carry a canonical
 *   link to their own URL. An idea page whose idea is not publicly readable
 *   (unknown id, deleted, merged, awaiting moderation) carries `noindex`
 *   instead, so the 200 shell is not reported as a soft 404.
 * - Board forms (submit, idea edit) and member profiles carry `noindex`.
 * - Every other shell route (login, admin, profile, inbox, private boards) carries
 *   `noindex`, except the public entry pages in INDEXABLE_PATHS.
 * - An unknown account or board, and a private board for an anonymous viewer, answer
 *   404 with that same noindex shell (see forRequest()). A path segment that names
 *   neither an existing account or board nor a system route also answers 404; system
 *   routes (SPA or API segments) answer 200.
 */
final class BoardShell
{
    /**
     * Shell paths that can be a board page: up to account + board + a board sub-path
     * (idea, idea edit), see isBoardSubpath(). A member profile fits the same shape.
     */
    private const BOARD_PATH_PATTERN = '#^/[a-z0-9-]{1,64}(?:/[a-z0-9-]{1,64}){0,4}/?$#';

    private const SLUG_PATTERN = '/^[a-z0-9-]{1,64}$/';

    /** Shell routes without board meta that search engines may index. */
    private const INDEXABLE_PATHS = ['/', '/signup', '/discover'];

    private const NOINDEX = '<meta name="robots" content="noindex">';

    /**
     * Top-level SPA and API segments that are not in SlugValidator::RESERVED_WORDS.
     * They are not reserved for new accounts or boards, so existing customer slugs
     * stay valid. The shell reads them as system routes only when no account or
     * board of that slug exists (namesExistingBoard()).
     */
    private const SPA_SEGMENTS = [
        'profile', 'operator', 'discover', 'account', 'members', 'password', 'digest',
        'mail', 'operator-nudges', 'operator-nudge', 'participant-mail', 'notification',
        'report', 'faq', 'avatar', 'appeal', 'health', 'ready',
    ];

    /**
     * Status (Search Console soft 404s, 2026-10-26): a path that names an account
     * or board that does not exist for an anonymous viewer answers 404 with the
     * same shell body (noindex), so the SPA's own not-found view still renders.
     * The 404 uses the public board lookup, like the public board API: a private,
     * locked, unconfirmed or compliance-frozen board is missing here too, so the
     * status reveals nothing a 404 from the API would not. A member of a private
     * board gets a 404 status with a working SPA, since the status is not read by the SPA.
     *
     * Fail-open: any config or database error answers 200 with the plain shell.
     * A 404 is only given when the lookup succeeded and found nothing. Turning an
     * outage into 404s would make search engines deindex the whole site.
     *
     * @param string|null $boardContext the `?board=` value of the request (read by the member profile only)
     */
    public static function forRequest(string $shellHtml, string $requestPath, string $configPath, ?string $boardContext = null): ShellResponse
    {
        if (preg_match(self::BOARD_PATH_PATTERN, $requestPath) !== 1) {
            return new ShellResponse(self::plainShell($shellHtml, $requestPath));
        }

        // Fail-open: a board path whose board cannot be looked up keeps the shell as is.
        if (!is_file($configPath)) {
            return new ShellResponse($shellHtml);
        }

        try {
            $config = Config::fromArray(require $configPath);
            $conn   = ConnectionFactory::create($config);

            $policy = ExtensionLoader::planPolicy(ExtensionLoader::fromConfig($config)) ?? new UnrestrictedPlanPolicy();

            return self::respond($shellHtml, $requestPath, $config, $conn, $policy, $boardContext);
        } catch (\Throwable) {
            // Fail-open on purpose: a config or database error keeps the 200 shell.
            return new ShellResponse($shellHtml);
        }
    }

    /**
     * The shell and status for an already connected request (forRequest() without
     * the config and connection setup). Throws on a database error; forRequest() catches it.
     */
    public static function respond(string $shellHtml, string $requestPath, Config $config, Connection $conn, PlanPolicy $planPolicy, ?string $boardContext = null): ShellResponse
    {
        if (preg_match(self::BOARD_PATH_PATTERN, $requestPath) !== 1) {
            return new ShellResponse(self::plainShell($shellHtml, $requestPath));
        }

        $html   = self::render($shellHtml, $requestPath, $config, $conn, $planPolicy, $boardContext)
            ?? self::plainShell($shellHtml, $requestPath);
        $exists = self::namesExistingBoard($requestPath, $config, $conn);

        return new ShellResponse($html, $exists ? 200 : 404);
    }

    /**
     * Whether the path answers 200 (resolve-first). An existing account (cloud) and
     * board named by the path are a page with the rules of render() and the idea
     * check below. A segment that names no account or board is a system route
     * (isSystemSegment()) and answers 200. Anything else is missing and answers 404.
     * Idea paths (idea/{id}, idea/{id}/edit) also need the idea to be publicly readable,
     * with the same rule as the public idea endpoint (IdeaRepository::findInBoard).
     */
    private static function namesExistingBoard(string $requestPath, Config $config, Connection $conn): bool
    {
        $segments = self::segments($requestPath);
        $cloud    = $config->routingMode === 'cloud';

        if ($cloud) {
            $accountSlug = $segments[0] ?? null;
            if ($accountSlug === null) {
                return true;
            }

            $accountId = self::accountId($conn, true, $accountSlug);
            if ($accountId === null) {
                return self::isSystemSegment($accountSlug);
            }
            $boardSlug = $segments[1] ?? null;
            $rest      = array_slice($segments, 2);
        } else {
            $accountId = self::accountId($conn, false, null);
            $boardSlug = $segments[0] ?? null;
            $rest      = array_slice($segments, 1);
        }

        if ($boardSlug === null) {
            return true;
        }

        $board = $accountId === null
            ? null
            : (new BoardRepository($conn))->findPublicBySlugForAccount($boardSlug, $accountId, false);
        if ($board === null) {
            return self::isSystemSegment($boardSlug);
        }

        $ideaPage = ($rest[0] ?? null) === 'idea'
            && (count($rest) === 2 || (count($rest) === 3 && $rest[2] === 'edit'));
        if (!$ideaPage) {
            return true;
        }

        return preg_match('/^\d+$/', $rest[1]) === 1
            && (new IdeaRepository($conn))->findInBoard((int) $board['id'], (int) $rest[1]) !== null;
    }

    /**
     * @return list<string>
     */
    private static function segments(string $requestPath): array
    {
        return array_values(array_filter(explode('/', trim($requestPath, '/')), static fn (string $s): bool => $s !== ''));
    }

    /**
     * A top-level SPA or API route: a reserved slug or one of SPA_SEGMENTS.
     */
    private static function isSystemSegment(string $slug): bool
    {
        return in_array($slug, SlugValidator::reservedWords(), true)
            || in_array($slug, self::SPA_SEGMENTS, true);
    }

    /**
     * The shell without board meta: `noindex` unless the path is a public entry page.
     */
    public static function plainShell(string $shellHtml, string $requestPath): string
    {
        $path = $requestPath === '/' ? '/' : rtrim($requestPath, '/');
        if (in_array($path, self::INDEXABLE_PATHS, true)) {
            return $shellHtml;
        }

        return self::injectHead($shellHtml, [self::NOINDEX]) ?? $shellHtml;
    }

    /**
     * @return string|null the shell with the board's meta, or null when the path is not a public board page
     */
    public static function render(string $shellHtml, string $requestPath, Config $config, Connection $conn, PlanPolicy $planPolicy, ?string $boardContext = null): ?string
    {
        $segments = self::segments($requestPath);
        $cloud    = $config->routingMode === 'cloud';

        if (self::isProfilePath($segments, $cloud)) {
            // Without a `?board=` context the profile keeps the Votepit default look.
            if ($boardContext === null) {
                return null;
            }

            return self::renderMeta($shellHtml, $config, $conn, $planPolicy, $cloud, $cloud ? $segments[0] : null, $boardContext, profile: true);
        }

        $lead = $cloud ? 2 : 1;
        $rest = array_slice($segments, $lead);
        if (count($segments) < $lead || !self::isBoardSubpath($rest)) {
            return null;
        }
        // Forms are not search results; every other board page is its own canonical.
        $form      = ($rest[0] ?? null) === 'submit' || ($rest[2] ?? null) === 'edit';
        $canonical = $form ? null : rtrim($config->appUrl, '/') . '/' . implode('/', $segments);
        $ideaId    = ($rest[0] ?? null) === 'idea' ? (int) $rest[1] : null;
        $segments  = array_slice($segments, 0, $lead);

        if ($cloud) {
            [$accountSlug, $boardSlug] = $segments;
        } else {
            $accountSlug = null;
            [$boardSlug] = $segments;
        }

        return self::renderMeta($shellHtml, $config, $conn, $planPolicy, $cloud, $accountSlug, $boardSlug, profile: false, canonical: $canonical, ideaId: $ideaId);
    }

    /**
     * The board's icon and share image (and, for a board page, its title and
     * site meta) in the shell's <head>. Null when there is nothing to add.
     */
    private static function renderMeta(
        string $shellHtml,
        Config $config,
        Connection $conn,
        PlanPolicy $planPolicy,
        bool $cloud,
        ?string $accountSlug,
        string $boardSlug,
        bool $profile,
        ?string $canonical = null,
        ?int $ideaId = null,
    ): ?string {
        if (preg_match(self::SLUG_PATTERN, $boardSlug) !== 1) {
            return null;
        }

        $accountId = self::accountId($conn, $cloud, $accountSlug);
        if ($accountId === null) {
            return null;
        }

        $board = (new BoardRepository($conn))->findPublicBySlugForAccount($boardSlug, $accountId, false);
        if ($board === null) {
            return null;
        }

        // Same visibility as the public idea endpoint: an unknown idea is not a page.
        if ($ideaId !== null && $canonical !== null && (new IdeaRepository($conn))->findInBoard((int) $board['id'], $ideaId) === null) {
            $canonical = null;
        }

        // Anonymous viewer: only the account plan counts. A downgraded board keeps
        // its stored images, but the shell must not point at them (the serve
        // endpoint would 404), so the same gate as the public payload applies.
        $plans    = new AccountPlanResolver(new AccountRepository($conn), new AccountMemberRepository($conn), $planPolicy);
        $plan     = $plans->forAccount($accountId, null);
        $variants = [];
        if ($planPolicy->isBrandingFieldAllowed($plan, 'favicon_og')) {
            foreach ((new BoardAssetRepository($conn))->listForBoard((int) $board['id']) as $asset) {
                $variants[$asset['variant']] = $asset['public_id'];
            }
        }

        $pathPrefix = $cloud ? '/' . $accountSlug : '';
        $boardBase  = rtrim($config->appUrl, '/') . $pathPrefix . '/' . $boardSlug;
        $name       = is_string($board['name'] ?? null) && $board['name'] !== '' ? $board['name'] : $boardSlug;

        $head = [];
        if (!$profile) {
            $head[] = '<meta property="og:site_name" content="Votepit">';
            $head[] = '<meta property="og:type" content="website">';
            $head[] = '<meta property="og:title" content="' . self::e($name) . '">';
            $head[] = '<meta property="og:url" content="' . self::e($boardBase) . '">';
            $head[] = '<meta name="twitter:title" content="' . self::e($name) . '">';
        }

        $ogId = $variants[BoardAssetProcessor::VARIANT_OG] ?? null;
        if ($ogId !== null) {
            $ogUrl  = $boardBase . '/assets/' . $ogId;
            $head[] = '<meta property="og:image" content="' . self::e($ogUrl) . '">';
            $head[] = '<meta property="og:image:width" content="1200">';
            $head[] = '<meta property="og:image:height" content="630">';
            $head[] = '<meta name="twitter:card" content="summary_large_image">';
            $head[] = '<meta name="twitter:image" content="' . self::e($ogUrl) . '">';
        } elseif (!$profile) {
            $head[] = '<meta name="twitter:card" content="summary">';
        }

        $html = $shellHtml;
        // Fallback chain: own favicon > app symbol (logo slot, only while the plan serves logos).
        $symbolId  = $planPolicy->isBrandingFieldAllowed($plan, 'logo_url') ? ($variants[BoardAssetProcessor::VARIANT_LOGO] ?? null) : null;
        $faviconId = $variants[BoardAssetProcessor::VARIANT_FAVICON] ?? $symbolId;
        if ($faviconId !== null) {
            // The board's own icon replaces the static Votepit icons for this page.
            $html   = (string) preg_replace('#<link rel="(?:shortcut )?icon"[^>]*>\s*#', '', $html);
            $head[] = '<link rel="icon" type="image/png" sizes="64x64" href="' . self::e($boardBase . '/assets/' . $faviconId) . '">';

            // Home-screen icon: the 180 px rendition; favicons stored before it existed stand in.
            $touchId = $variants[BoardAssetProcessor::VARIANT_TOUCH_ICON] ?? null;
            $html    = (string) preg_replace('#<link rel="apple-touch-icon"[^>]*>\s*#', '', $html);
            $head[]  = $touchId !== null
                ? '<link rel="apple-touch-icon" sizes="180x180" href="' . self::e($boardBase . '/assets/' . $touchId) . '">'
                : '<link rel="apple-touch-icon" href="' . self::e($boardBase . '/assets/' . $faviconId) . '">';
        }

        if ($profile && $head === []) {
            return null;
        }

        if (!$profile) {
            $head = array_merge(['<title>' . self::e($name) . ' · Votepit</title>'], $head);
            $html = (string) preg_replace('#<title>[^<]*</title>#', '', $html, 1);
        }
        $head[] = $canonical !== null
            ? '<link rel="canonical" href="' . self::e($canonical) . '">'
            : self::NOINDEX;

        return self::injectHead($html, $head);
    }

    /**
     * @param list<string> $head
     */
    private static function injectHead(string $html, array $head): ?string
    {
        $position = strpos($html, '</head>');
        if ($position === false) {
            return null;
        }

        return substr($html, 0, $position) . implode("\n    ", $head) . "\n  " . substr($html, $position);
    }

    /**
     * The member profile route: `{account}/members/{id}/profile` (cloud) or
     * `members/{id}/profile` (self-host). Shape only; the account and board are
     * resolved and gated by renderMeta().
     *
     * @param list<string> $segments
     */
    private static function isProfilePath(array $segments, bool $cloud): bool
    {
        $tail = $cloud ? array_slice($segments, 1) : $segments;

        return count($tail) === 3
            && $tail[0] === 'members'
            && preg_match('/^\d+$/', $tail[1]) === 1
            && $tail[2] === 'profile'
            && ($cloud ? count($segments) === 4 : true);
    }

    /**
     * The account the path names: the default account in self-host, the path
     * account slug in cloud. Null when the slug is unknown.
     */
    private static function accountId(Connection $conn, bool $cloud, ?string $accountSlug): ?int
    {
        if (!$cloud) {
            return (new AccountRepository($conn))->defaultAccountId();
        }

        $account = $accountSlug === null ? null : (new AccountRepository($conn))->findBySlug($accountSlug);

        return $account === null ? null : (int) $account['id'];
    }

    /**
     * The board-scoped SPA sub-paths that get the board's meta: /, /roadmap, /submit,
     * /impressum, /idea/{id} and /idea/{id}/edit. Anything else is not a board page.
     *
     * @param list<string> $rest
     */
    private static function isBoardSubpath(array $rest): bool
    {
        return match (count($rest)) {
            0 => true,
            1 => in_array($rest[0], ['roadmap', 'submit', 'impressum'], true),
            2 => $rest[0] === 'idea' && preg_match('/^\d+$/', $rest[1]) === 1,
            3 => $rest[0] === 'idea' && preg_match('/^\d+$/', $rest[1]) === 1 && $rest[2] === 'edit',
            default => false,
        };
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
