<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Domain\BoardThemeMode;
use Votepit\Domain\BrandResolver;
use Votepit\Http\Middleware\AccountContextMiddleware;
use Votepit\Http\Middleware\SecurityHeaderMiddleware;
use Votepit\Http\Support\JsonError;
use Votepit\Http\Support\WidgetGate;
use Votepit\Persistence\BoardRepository;
use Votepit\Persistence\BoardWidgetRepository;
use Votepit\Security\WidgetOriginValidator;

/**
 * GET {account}/{board}/widget — the iframe document of the embeddable
 * widget (ADR 0005 §1/§2). Served by PHP (never statically by Apache, see
 * public/index.php and public/.htaccess) because it is the ONLY response
 * whose `frame-ancestors` is relaxed — to exactly this board's validated
 * origin list (SecurityHeaderMiddleware::INTERNAL_FRAME_ANCESTORS), without
 * X-Frame-Options.
 *
 * 404 (fail-secure) unless: the board resolves through the PUBLIC board
 * chokepoint (public/unlisted, confirmed, unlocked, not compliance-frozen —
 * a private board is never embeddable), the widget is live (WidgetGate:
 * plan allows it, at least one origin configured) and the built widget
 * shell exists.
 *
 * The shell is the Vite-built `widget.html` (app/widget.html → dist →
 * public/). Its only dynamic part is one `<meta name="votepit-widget">`
 * tag carrying JSON (origins for the postMessage check, board slug/name),
 * HTML-attribute-escaped — no inline script, the CSP stays script-src
 * 'self'. No tenant-supplied active content is ever emitted.
 *
 * Install detection: when the iframe request's Referer is one of the
 * allowed origins, it is recorded (throttled) for the owner's settings page.
 */
final readonly class WidgetPageAction
{
    public function __construct(
        private BoardRepository $boards,
        private WidgetGate $gate,
        private BoardWidgetRepository $widgets,
        private string $shellPath,
        private ?BrandResolver $brand = null,
    ) {}

    /** @param array<string, mixed> $args */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $slug      = is_string($args['board'] ?? null) ? $args['board'] : '';
        $accountId = (int) $request->getAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID);
        $board     = $this->boards->findPublicBySlugForAccount($slug, $accountId);
        $config    = is_array($board) ? $this->gate->activeConfig($accountId, (int) $board['id']) : null;
        $shell     = is_file($this->shellPath) ? file_get_contents($this->shellPath) : false;

        if (!is_array($board) || $config === null || !is_string($shell) || !str_contains($shell, '</head>')) {
            return JsonError::respond($response, 404, 'not_found', 'Board not found.')
                ->withHeader(SecurityHeaderMiddleware::INTERNAL_COOKIELESS, '1');
        }

        $origins = $config['allowed_origins'];

        $referrerOrigin = WidgetOriginValidator::originOf($request->getHeaderLine('Referer'));
        if ($referrerOrigin !== null && in_array($referrerOrigin, $origins, true)) {
            $this->widgets->recordSeen((int) $board['id'], $referrerOrigin);
        }

        // P-32 widget theming (Pro): only validated colour tokens, never CSS or
        // tenant markup. Absent for every other plan, so the default look stays.
        $theme = $this->brand?->widgetTheme($board);

        $payload = [
            'origins' => $origins,
            'board'   => [
                'slug' => $slug,
                'name' => is_string($board['name'] ?? null) ? $board['name'] : $slug,
                // P-26: the widget follows the board's display mode (system = its own prefers-color-scheme).
                'theme_mode' => BoardThemeMode::normalize($board['theme_mode'] ?? null),
            ],
        ];
        if (is_array($theme) && $theme !== []) {
            $payload['theme'] = $theme;
        }

        $meta = (string) json_encode($payload, JSON_UNESCAPED_SLASHES);
        $tag  = '<meta name="votepit-widget" content="' . htmlspecialchars($meta, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">';
        // substr_replace, not preg_replace: the board name must never be
        // interpreted as a regex back-reference (`$1`) in the replacement.
        $html = substr_replace($shell, $tag . "\n", (int) strpos($shell, '</head>'), 0);

        $response->getBody()->write($html);

        return $response
            ->withStatus(200)
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader(SecurityHeaderMiddleware::INTERNAL_FRAME_ANCESTORS, implode(' ', $origins))
            ->withHeader(SecurityHeaderMiddleware::INTERNAL_COOKIELESS, '1');
    }
}
