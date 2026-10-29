<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use PHPUnit\Framework\TestCase;

/**
 * Guards core/.htaccess, the repository-root default-deny file for hosts whose
 * document root is the repo root (documentation/installation.md, step 5).
 *
 * Losing or loosening it would expose config/config.php (secrets), src/,
 * vendor/ and migrations/ by URL. Two layers:
 *  - static: the file exists and keeps its rewrite-into-public/ shape and its
 *    deny fallback;
 *  - live: boots the real local httpd with both .htaccess files over a tree
 *    that holds secret markers outside public/ and asserts none leaks. Skipped
 *    where httpd (mod_authz_core/mod_rewrite/mod_headers) is not available.
 */
final class RootHtaccessTest extends TestCase
{
    private const SECRET = 'SECRET-MARKER-DO-NOT-LEAK';

    private function coreRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    public function test_root_htaccess_exists_and_routes_everything_into_public(): void
    {
        $path = $this->coreRoot() . '/.htaccess';
        self::assertFileExists($path, 'core/.htaccess is missing: config/, src/, vendor/ would be served by URL on repo-root docroots.');

        $rules = $this->activeLines($path);
        self::assertContains('RewriteRule ^(?!public/)(.*)$ public/$1 [L]', $rules, 'Every request must be rewritten into public/.');
        self::assertContains('Require all denied', $rules, 'Fallback without mod_rewrite must deny everything.');
        self::assertContains('Deny from all', $rules, 'Fallback for Apache 2.2 must deny everything.');
    }

    public function test_root_htaccess_is_not_loosened(): void
    {
        $rules = $this->activeLines($this->coreRoot() . '/.htaccess');
        $forbidden = ['Require all granted', 'Allow from all', 'Satisfy any', 'Options +Indexes', 'Options +FollowSymLinks'];
        foreach ($rules as $line) {
            foreach ($forbidden as $bad) {
                self::assertStringNotContainsString($bad, $line, "core/.htaccess must not contain '{$bad}'.");
            }
            self::assertDoesNotMatchRegularExpression('#^RewriteRule\s+\S+\s+-\s#', $line, 'A RewriteRule with "-" substitution would bypass the rewrite.');
        }
    }

    /** @return list<string> Non-comment, trimmed, non-empty lines. */
    private function activeLines(string $path): array
    {
        $parts = preg_split('/\R/', (string) file_get_contents($path));
        $lines = $parts === false ? [] : $parts;

        return array_values(array_filter(
            array_map(trim(...), $lines),
            static fn (string $l): bool => $l !== '' && !str_starts_with($l, '#'),
        ));
    }

    // ── Live Apache ──────────────────────────────────────────────────────

    public function test_live_apache_never_serves_files_outside_public(): void
    {
        $httpd   = $this->which('httpd');
        $modules = $this->moduleDir();
        if ($httpd === null || $modules === null) {
            self::markTestSkipped('local httpd (mod_authz_core/mod_rewrite/mod_headers/mod_dir) not available');
        }

        $dir  = sys_get_temp_dir() . '/votepit-roothtaccess-' . bin2hex(random_bytes(4));
        $root = $dir . '/docroot';
        $this->tree($root, [
            '.htaccess'            => (string) file_get_contents($this->coreRoot() . '/.htaccess'),
            'public/.htaccess'     => (string) file_get_contents($this->coreRoot() . '/public/.htaccess'),
            'public/index.html'    => 'FRONT-SHELL',
            'public/index.php'     => 'FRONT-CONTROLLER',
            'config/config.php'    => "<?php return ['pass' => '" . self::SECRET . "'];",
            'src/Secret.php'       => '<?php // ' . self::SECRET,
            'vendor/autoload.php'  => '<?php // ' . self::SECRET,
            'migrations/0000.sql'  => '-- ' . self::SECRET,
            'bin/migrate.php'      => '<?php // ' . self::SECRET,
            'composer.json'        => '{"secret":"' . self::SECRET . '"}',
        ]);
        touch($dir . '/mime.types');

        $port = random_int(20000, 40000);
        file_put_contents($dir . '/httpd.conf', implode("\n", [
            'ServerRoot "/usr"',
            "Listen 127.0.0.1:{$port}",
            ...array_map(static fn (string $m): string => "LoadModule {$m}_module {$modules}/mod_{$m}.so", ['mpm_prefork', 'authz_core', 'unixd', 'mime', 'dir', 'headers', 'rewrite']),
            'ServerName localhost',
            "PidFile {$dir}/httpd.pid",
            "ErrorLog {$dir}/error.log",
            "TypesConfig {$dir}/mime.types",
            "DocumentRoot \"{$root}\"",
            "<Directory \"{$root}\">",
            '  AllowOverride All',
            '  Require all granted',
            '</Directory>',
            'DirectoryIndex index.html index.php',
        ]) . "\n");

        exec(escapeshellarg($httpd) . ' -f ' . escapeshellarg($dir . '/httpd.conf') . ' -k start 2>&1', $out, $code);
        if ($code !== 0) {
            self::markTestSkipped('httpd could not start: ' . implode(' ', $out));
        }

        try {
            $get = static function (string $path) use ($port): array {
                $deadline = microtime(true) + 5;
                do {
                    $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 1);
                    if ($socket === false) {
                        usleep(50_000);
                    }
                } while ($socket === false && microtime(true) < $deadline);
                self::assertNotFalse($socket, 'httpd did not come up');
                fwrite($socket, "GET {$path} HTTP/1.0\r\nHost: localhost\r\n\r\n");
                $raw = (string) stream_get_contents($socket);
                fclose($socket);
                [$head, $body] = array_pad(explode("\r\n\r\n", $raw, 2), 2, '');
                preg_match('#^HTTP/\S+ (\d{3})#', $head, $m);

                return ['status' => (int) ($m[1] ?? 0), 'body' => $body];
            };

            // Every probe: no secret marker may ever appear in a response.
            $probes = ['/', '/board/idea', '/config/config.php', '/src/Secret.php', '/vendor/autoload.php',
                '/migrations/0000.sql', '/bin/migrate.php', '/composer.json', '/public/index.php', '/.htaccess'];
            foreach ($probes as $path) {
                $response = $get($path);
                self::assertStringNotContainsString(self::SECRET, $response['body'], "GET {$path} leaked a file outside public/");
            }

            // Routing works: root and unknown routes reach the front controller.
            self::assertSame(200, $get('/')['status']);
            self::assertStringContainsString('FRONT-SHELL', $get('/')['body']);
            self::assertStringContainsString('FRONT-CONTROLLER', $get('/board/idea')['body']);

            // Dotfiles inside public/ stay denied.
            self::assertSame(403, $get('/.htaccess')['status']);
        } finally {
            exec(escapeshellarg($httpd) . ' -f ' . escapeshellarg($dir . '/httpd.conf') . ' -k stop 2>&1');
            usleep(300_000);
            $this->removeDir($dir);
        }
    }

    /** @param array<string, string> $files relative path => contents */
    private function tree(string $root, array $files): void
    {
        foreach ($files as $rel => $contents) {
            $path = $root . '/' . $rel;
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0o777, true);
            }
            file_put_contents($path, $contents);
        }
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }

    private function which(string $binary): ?string
    {
        $path = trim((string) shell_exec('command -v ' . escapeshellarg($binary) . ' 2>/dev/null'));

        return $path !== '' && is_executable($path) ? $path : null;
    }

    private function moduleDir(): ?string
    {
        foreach (['/usr/libexec/apache2', '/usr/lib/apache2/modules', '/usr/lib64/httpd/modules'] as $dir) {
            if (is_file($dir . '/mod_rewrite.so') && is_file($dir . '/mod_headers.so') && is_file($dir . '/mod_mime.so') && is_file($dir . '/mod_mpm_prefork.so')) {
                return $dir;
            }
        }

        return null;
    }
}
