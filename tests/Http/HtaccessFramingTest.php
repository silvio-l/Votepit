<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins the interplay of the static Apache headers in public/.htaccess with
 * the dynamic framing headers of the widget iframe page (ADR 0005).
 *
 * The static `Header always set` X-Frame-Options/CSP/CORP must apply to
 * every response EXCEPT a 200 on `GET /{account}/{board}/widget` — Apache
 * would otherwise replace or duplicate SecurityHeaderMiddleware's relaxed
 * frame-ancestors and break the embed (or, worse, a sloppy exclusion would
 * leave other paths frameable).
 *
 * Two layers:
 *  - static: parses .htaccess, extracts the request-line pattern and the
 *    conditions and checks them against request lines (always runs);
 *  - live: boots the real local httpd with this exact .htaccess and a stub
 *    PHP front controller under php-cgi (the shared-hosting SAPI shape) and
 *    asserts the headers on the wire. Skipped where httpd/mod_headers/
 *    php-cgi are not available (e.g. CI).
 */
final class HtaccessFramingTest extends TestCase
{
    private function htaccess(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/public/.htaccess');
    }

    /**
     * Every active (non-comment) `Header always set <name>` directive line.
     *
     * @return list<string>
     */
    private function headerLines(string $name): array
    {
        $lines = [];
        foreach (explode("\n", $this->htaccess()) as $line) {
            $line = trim($line);
            if (preg_match('/^Header\s+always\s+set\s+' . preg_quote($name, '/') . '\s/i', $line) === 1) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /** The THE_REQUEST pattern used in the framing conditions, as a PCRE. */
    private function widgetRequestPattern(): string
    {
        $lines = $this->headerLines('X-Frame-Options');
        self::assertCount(1, $lines);
        self::assertSame(1, preg_match('/%\{THE_REQUEST\} !~ m#(.+?)# \|\| %\{REQUEST_STATUS\} != 200"$/', $lines[0], $m));

        return '#' . $m[1] . '#';
    }

    public function test_framing_headers_are_conditional_on_the_widget_request_line_and_status(): void
    {
        foreach (['X-Frame-Options', 'Content-Security-Policy'] as $header) {
            $lines = $this->headerLines($header);
            self::assertCount(1, $lines, $header . ' must be set exactly once');
            self::assertStringContainsString('"expr=%{THE_REQUEST} !~ m#', $lines[0]);
            self::assertStringEndsWith('|| %{REQUEST_STATUS} != 200"', $lines[0], $header . ' must fall back to strict on any non-200');
        }
        self::assertStringContainsString("frame-ancestors 'none'", $this->headerLines('Content-Security-Policy')[0]);

        // No unconditional framing header anywhere (would hit the widget page).
        self::assertDoesNotMatchRegularExpression('/^\s*Header\s+always\s+set\s+(X-Frame-Options|Content-Security-Policy)\s+"[^"]*"\s*$/mi', $this->htaccess());
    }

    /** @return iterable<string, array{string, bool}> */
    public static function requestLines(): iterable
    {
        // [request line, is the relaxed widget page]
        yield 'cloud widget'               => ['GET /acme/ideas/widget HTTP/1.1', true];
        yield 'self-host widget'           => ['GET /ideas/widget HTTP/1.1', true];
        yield 'widget with query'          => ['GET /acme/ideas/widget?v=2 HTTP/2.0', true];
        yield 'POST to widget'             => ['POST /acme/ideas/widget HTTP/1.1', false];
        yield 'HEAD widget'                => ['HEAD /acme/ideas/widget HTTP/1.1', false];
        yield 'identify'                   => ['POST /acme/ideas/widget/identify HTTP/1.1', false];
        yield 'token route'                => ['GET /acme/ideas/widget/ideas HTTP/1.1', false];
        yield 'board home'                 => ['GET /acme/ideas HTTP/1.1', false];
        yield 'suffix'                     => ['GET /acme/ideas/widgetx HTTP/1.1', false];
        yield 'too deep'                   => ['GET /a/b/c/widget HTTP/1.1', false];
        yield 'dot segments'               => ['GET /acme/ideas/widget/../widget HTTP/1.1', false];
        yield 'encoded query'              => ['GET /acme/ideas/widget%3Fx HTTP/1.1', false];
        yield 'uppercase'                  => ['GET /ACME/ideas/widget HTTP/1.1', false];
        yield 'double slash'               => ['GET //acme/ideas/widget HTTP/1.1', false];
        yield 'admin'                      => ['GET /acme/admin/boards/ideas/widget HTTP/1.1', false];
        yield 'loader script'              => ['GET /widget.js HTTP/1.1', false];
        yield 'spa root'                   => ['GET / HTTP/1.1', false];
    }

    #[DataProvider('requestLines')]
    public function test_only_the_widget_page_request_line_is_excluded(string $requestLine, bool $isWidgetPage): void
    {
        self::assertSame($isWidgetPage, preg_match($this->widgetRequestPattern(), $requestLine) === 1);
    }

    public function test_pattern_is_in_lockstep_with_the_front_controller(): void
    {
        $index = (string) file_get_contents(dirname(__DIR__, 2) . '/public/index.php');
        self::assertStringContainsString("preg_match('#^/(?:[a-z0-9-]+/)?[a-z0-9-]+/widget\$#', \$requestPath)", $index);
        self::assertStringContainsString('m#^GET /(?:[a-z0-9-]+/)?[a-z0-9-]+/widget(?:\?\S*)? HTTP/#', $this->htaccess());
    }

    public function test_loader_script_is_cross_origin_loadable(): void
    {
        self::assertStringContainsString('Header always set Cross-Origin-Resource-Policy "cross-origin" "expr=%{REQUEST_URI} == \'/widget.js\'"', $this->htaccess());
        self::assertStringContainsString('Header always set Access-Control-Allow-Origin "*" "expr=%{REQUEST_URI} == \'/widget.js\'"', $this->htaccess());
        $sameOrigin = $this->headerLines('Cross-Origin-Resource-Policy')[0];
        self::assertStringContainsString("&& %{REQUEST_URI} != '/widget.js'", $sameOrigin);
    }

    // ── Live Apache ──────────────────────────────────────────────────────

    public function test_live_apache_headers_on_the_wire(): void
    {
        $httpd   = $this->which('httpd');
        $phpCgi  = $this->which('php-cgi');
        $modules = $this->moduleDir();
        if ($httpd === null || $phpCgi === null || $modules === null) {
            self::markTestSkipped('local httpd (mod_headers/mod_rewrite/mod_cgi) + php-cgi not available');
        }

        $dir  = sys_get_temp_dir() . '/votepit-htaccess-' . bin2hex(random_bytes(4));
        $root = $dir . '/docroot';
        mkdir($root, 0o777, true);
        copy(dirname(__DIR__, 2) . '/public/.htaccess', $root . '/.htaccess');
        file_put_contents($root . '/widget.js', "/* loader */\n");
        file_put_contents($root . '/index.html', "<!doctype html>\n");
        // Stub front controller: emits what SecurityHeaderMiddleware emits.
        file_put_contents($root . '/index.php', "#!{$phpCgi}\n" . <<<'PHP_WRAP'
            <?php
            $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
            if (str_contains($path, 'crash')) {
                http_response_code(500);
            } elseif (str_contains($path, 'off')) {
                http_response_code(404);
                header('X-Frame-Options: DENY');
                header("Content-Security-Policy: frame-ancestors 'none'");
            } elseif (preg_match('#^/(?:[a-z0-9-]+/)?[a-z0-9-]+/widget$#', $path) === 1) {
                header('Content-Security-Policy: frame-ancestors https://shop.example.com');
                header('Cross-Origin-Resource-Policy: cross-origin');
            } else {
                header('X-Frame-Options: DENY');
                header("Content-Security-Policy: frame-ancestors 'none'");
            }
            echo 'ok';
            PHP_WRAP);
        chmod($root . '/index.php', 0o755);

        touch($dir . '/mime.types');
        $port = random_int(20000, 40000);
        file_put_contents($dir . '/httpd.conf', implode("\n", [
            'ServerRoot "/usr"',
            "Listen 127.0.0.1:{$port}",
            ...array_map(static fn (string $m): string => "LoadModule {$m}_module {$modules}/mod_{$m}.so", ['mpm_prefork', 'authz_core', 'unixd', 'mime', 'dir', 'headers', 'rewrite', 'cgi']),
            'ServerName localhost',
            "PidFile {$dir}/httpd.pid",
            "ErrorLog {$dir}/error.log",
            "TypesConfig {$dir}/mime.types",
            "DocumentRoot \"{$root}\"",
            "<Directory \"{$root}\">",
            '  AllowOverride All',
            '  Options +ExecCGI',
            '  AddHandler cgi-script .php',
            '  Require all granted',
            '</Directory>',
            'DirectoryIndex index.html',
        ]) . "\n");

        exec(escapeshellarg($httpd) . ' -f ' . escapeshellarg($dir . '/httpd.conf') . ' -k start 2>&1', $out, $code);
        if ($code !== 0) {
            self::markTestSkipped('httpd could not start: ' . implode(' ', $out));
        }

        try {
            $get = static function (string $method, string $path) use ($port): array {
                $deadline = microtime(true) + 5;
                do {
                    $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 1);
                    if ($socket === false) {
                        usleep(50_000);
                    }
                } while ($socket === false && microtime(true) < $deadline);
                self::assertNotFalse($socket, 'httpd did not come up');
                fwrite($socket, "{$method} {$path} HTTP/1.0\r\nHost: localhost\r\n\r\n");
                $raw = (string) stream_get_contents($socket);
                fclose($socket);
                [$head] = explode("\r\n\r\n", $raw, 2);

                return array_slice(explode("\r\n", $head), 0);
            };
            $headers = static fn (array $lines, string $name): array => array_values(array_filter(
                $lines,
                static fn (string $l): bool => stripos($l, $name . ':') === 0,
            ));

            // Widget page: PHP's relaxed values only, no XFO, nothing duplicated.
            $widget = $get('GET', '/acme/ideas/widget');
            self::assertStringContainsString(' 200 ', $widget[0]);
            self::assertSame([], $headers($widget, 'X-Frame-Options'));
            self::assertSame(['Content-Security-Policy: frame-ancestors https://shop.example.com'], $headers($widget, 'Content-Security-Policy'));
            self::assertSame(['Cross-Origin-Resource-Policy: cross-origin'], $headers($widget, 'Cross-Origin-Resource-Policy'));

            // Everything else, including error statuses on the widget path: strict.
            foreach ([['GET', '/acme/ideas'], ['GET', '/acme/ideas/widget/ideas'], ['POST', '/acme/ideas/widget'], ['GET', '/acme/off/widget'], ['GET', '/acme/crash/widget'], ['GET', '/'], ['GET', '/widget.js']] as [$method, $path]) {
                $lines = $get($method, $path);
                self::assertNotSame([], $headers($lines, 'X-Frame-Options'), "{$method} {$path} must send X-Frame-Options");
                foreach ($headers($lines, 'X-Frame-Options') as $line) {
                    self::assertSame('X-Frame-Options: DENY', $line, "{$method} {$path}");
                }
                foreach ($headers($lines, 'Content-Security-Policy') as $line) {
                    self::assertStringContainsString("frame-ancestors 'none'", $line, "{$method} {$path}");
                }
            }

            $loader = $get('GET', '/widget.js');
            self::assertSame(['Cross-Origin-Resource-Policy: cross-origin'], $headers($loader, 'Cross-Origin-Resource-Policy'));
            self::assertSame(['Access-Control-Allow-Origin: *'], $headers($loader, 'Access-Control-Allow-Origin'));
        } finally {
            exec(escapeshellarg($httpd) . ' -f ' . escapeshellarg($dir . '/httpd.conf') . ' -k stop 2>&1');
            usleep(300_000);
            exec('rm -rf ' . escapeshellarg($dir));
        }
    }

    private function which(string $binary): ?string
    {
        $path = trim((string) shell_exec('command -v ' . escapeshellarg($binary) . ' 2>/dev/null'));

        return $path !== '' && is_executable($path) ? $path : null;
    }

    private function moduleDir(): ?string
    {
        foreach (['/usr/libexec/apache2', '/usr/lib/apache2/modules', '/usr/lib64/httpd/modules'] as $dir) {
            if (is_file($dir . '/mod_headers.so') && is_file($dir . '/mod_cgi.so') && is_file($dir . '/mod_mime.so') && is_file($dir . '/mod_mpm_prefork.so')) {
                return $dir;
            }
        }

        return null;
    }
}
