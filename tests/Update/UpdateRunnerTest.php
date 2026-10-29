<?php

declare(strict_types=1);

namespace Votepit\Tests\Update;

use PHPUnit\Framework\TestCase;
use Votepit\Update\CommunityEdition;
use Votepit\Update\CommunityReleaseClient;
use Votepit\Update\MaintenanceMode;
use Votepit\Update\UpdateException;
use Votepit\Update\UpdateRunner;

/**
 * The self-update flow end to end with temporary directories, a fake GitHub and
 * throwaway minisign keys. Nothing here touches the network or real keys.
 */
final class UpdateRunnerTest extends TestCase
{
    private const OLD = '0.1.7';
    private const NEW = '0.1.8';

    private string $dir;
    private string $root;
    private string $releaseDir;
    private FakeReleaseHttp $http;
    /** @var array{publicKeyLine: string, secretKey: string} */
    private array $key;
    /** @var list<string> */
    private array $log = [];

    protected function setUp(): void
    {
        $this->dir        = sys_get_temp_dir() . '/votepit-runner-' . bin2hex(random_bytes(6));
        $this->root       = $this->dir . '/install';
        $this->releaseDir = $this->dir . '/release';
        mkdir($this->root, 0o700, true);
        mkdir($this->releaseDir, 0o700, true);
        $this->key  = MinisignFixture::keyPair();
        $this->http = new FakeReleaseHttp();
        $this->installation(self::OLD);
        $this->publish(self::NEW);
    }

    protected function tearDown(): void
    {
        UpdateRunner::removeTree($this->dir);
    }

    /** A running installation: managed code plus operator data that must survive. */
    private function installation(string $version): void
    {
        $files = [
            'composer.json'          => json_encode(['version' => $version]),
            'vendor/autoload.php'    => '<?php // old',
            'public/index.php'       => '<?php // old',
            'public/index.html'      => 'old',
            'src/Old.php'            => '<?php // old',
            'bin/migrate.php'        => '<?php // old',
            'migrations/0000.sql'    => '-- old',
            'config/config.php'      => '<?php return ["operator" => true];',
            'storage/upload.txt'     => 'operator upload',
            'logs/audit.log'         => 'operator log',
        ];
        foreach ($files as $path => $content) {
            $this->write($this->root . '/' . $path, (string) $content);
        }
    }

    /** Builds a release archive, its checksum and signature, and registers them with the fake GitHub. */
    /** @param array{publicKeyLine: string, secretKey: string}|null $key */
    private function publish(string $version, ?array $key = null, bool $badChecksum = false, string $tag = ''): void
    {
        $tag     = $tag !== '' ? $tag : 'v' . $version;
        $archive = CommunityEdition::archiveName($version);
        $zipPath = $this->releaseDir . '/' . $archive;

        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        foreach ([
            'composer.json'       => json_encode(['version' => $version]),
            'vendor/autoload.php' => '<?php // new',
            'public/index.php'    => '<?php // new',
            'public/index.html'   => 'new',
            'src/New.php'         => '<?php // new',
            'bin/migrate.php'     => '<?php // new',
            'migrations/0000.sql' => '-- new',
        ] as $path => $content) {
            $zip->addFromString('votepit/' . $path, (string) $content);
        }
        $zip->close();

        $sha = (string) hash_file('sha256', $zipPath);
        if ($badChecksum) {
            $sha = str_repeat('0', 64);
        }
        file_put_contents($zipPath . '.sha256', $sha . "  {$archive}\n");
        file_put_contents($zipPath . '.minisig', MinisignFixture::sign((string) file_get_contents($zipPath), $key ?? $this->key));

        $base = 'https://github.com/' . CommunityEdition::GITHUB_REPOSITORY . '/releases/download/' . $tag . '/';
        $assets = [];
        foreach ([$archive, $archive . '.sha256', $archive . '.minisig'] as $name) {
            $assets[] = ['name' => $name, 'browser_download_url' => $base . $name];
            $this->http->files[$base . $name] = $this->releaseDir . '/' . $name;
        }

        $json = json_encode(['tag_name' => $tag, 'draft' => false, 'prerelease' => false, 'assets' => $assets]);
        $this->http->bodies[CommunityEdition::LATEST_RELEASE_URL] = (string) $json;
        $this->http->bodies[sprintf(CommunityEdition::RELEASE_BY_TAG_URL, $tag)] = (string) $json;
    }

    private function write(string $path, string $content): void
    {
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0o700, true);
        }
        file_put_contents($path, $content);
    }

    /** @param list<string>|null $keys */
    private function runner(bool $confirm = true, bool $migrationsOk = true, ?array $keys = null): UpdateRunner
    {
        $releases = new CommunityReleaseClient($this->http, 'test');

        return new UpdateRunner(
            $this->root,
            self::OLD,
            $releases,
            $this->http,
            $keys ?? [$this->key['publicKeyLine']],
            static fn (): bool => $confirm,
            fn (string $tree): bool => $migrationsOk && is_file($tree . '/bin/migrate.php'),
            function (string $line): void {
                $this->log[] = $line;
            },
        );
    }

    private function version(): string
    {
        return (string) json_decode((string) file_get_contents($this->root . '/composer.json'), true)['version'];
    }

    public function test_a_signed_release_is_installed_and_operator_data_survives(): void
    {
        $status = $this->runner()->run(null, false);

        self::assertSame(UpdateRunner::STATUS_UPDATED, $status);
        self::assertSame(self::NEW, $this->version());
        self::assertContains('Signature verified.', $this->log);
        self::assertFileExists($this->root . '/src/New.php');
        self::assertFileDoesNotExist($this->root . '/src/Old.php');
        self::assertSame('<?php return ["operator" => true];', file_get_contents($this->root . '/config/config.php'));
        self::assertSame('operator upload', file_get_contents($this->root . '/storage/upload.txt'));
        self::assertSame('operator log', file_get_contents($this->root . '/logs/audit.log'));
        self::assertDirectoryDoesNotExist($this->root . '/storage/update');
        self::assertFalse((new MaintenanceMode($this->root))->isEnabled());
    }

    public function test_check_only_reports_availability_and_changes_nothing(): void
    {
        self::assertSame(UpdateRunner::STATUS_AVAILABLE, $this->runner()->run(null, true));
        self::assertSame(self::OLD, $this->version());
        self::assertDirectoryDoesNotExist($this->root . '/storage/update');
    }

    public function test_the_same_version_is_up_to_date(): void
    {
        $this->publish(self::OLD);

        self::assertSame(UpdateRunner::STATUS_UP_TO_DATE, $this->runner()->run(null, false));
        self::assertSame(self::OLD, $this->version());
    }

    public function test_a_downgrade_is_refused(): void
    {
        $this->publish('0.1.6');

        $this->expectException(UpdateException::class);
        $this->expectExceptionMessage('Downgrades are refused');
        $this->runner()->run('0.1.6', false);
    }

    public function test_a_signature_from_another_key_installs_nothing(): void
    {
        $this->publish(self::NEW, MinisignFixture::keyPair());

        try {
            $this->runner()->run(null, false);
            self::fail('a foreign signature was accepted');
        } catch (UpdateException $e) {
            self::assertStringContainsString('does not verify', $e->getMessage());
        }
        self::assertSame(self::OLD, $this->version());
    }

    public function test_a_wrong_checksum_installs_nothing(): void
    {
        $this->publish(self::NEW, null, true);

        try {
            $this->runner()->run(null, false);
            self::fail('a wrong checksum was accepted');
        } catch (UpdateException $e) {
            self::assertStringContainsString('checksum does not match', $e->getMessage());
        }
        self::assertSame(self::OLD, $this->version());
    }

    public function test_placeholder_keys_refuse_to_run(): void
    {
        $this->expectException(UpdateException::class);
        $this->expectExceptionMessage('not configured yet');

        $this->runner(keys: ['PLACEHOLDER-PRIMARY-PUBLIC-KEY', 'PLACEHOLDER-EMERGENCY-PUBLIC-KEY'])->run(null, false);
    }

    public function test_a_declined_backup_confirmation_aborts_without_changes(): void
    {
        self::assertSame(UpdateRunner::STATUS_ABORTED, $this->runner(confirm: false)->run(null, false));
        self::assertSame(self::OLD, $this->version());
        self::assertDirectoryDoesNotExist($this->root . '/storage/update');
    }

    public function test_failed_migrations_restore_the_previous_code_and_keep_maintenance_on(): void
    {
        try {
            $this->runner(migrationsOk: false)->run(null, false);
            self::fail('failed migrations were reported as success');
        } catch (UpdateException $e) {
            self::assertStringContainsString('maintenance mode stays ON', $e->getMessage());
        }

        self::assertSame(self::OLD, $this->version());
        self::assertFileExists($this->root . '/src/Old.php');
        self::assertFileDoesNotExist($this->root . '/src/New.php');
        self::assertTrue((new MaintenanceMode($this->root))->isEnabled());
    }

    public function test_a_release_for_another_version_than_its_name_is_refused(): void
    {
        // The archive is named 0.1.8 but its composer.json claims 0.1.9.
        $zipPath = $this->releaseDir . '/' . CommunityEdition::archiveName(self::NEW);
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('votepit/composer.json', (string) json_encode(['version' => '0.1.9']));
        $zip->addFromString('votepit/vendor/autoload.php', '<?php');
        $zip->addFromString('votepit/public/index.php', '<?php');
        $zip->addFromString('votepit/public/index.html', 'x');
        $zip->addFromString('votepit/src/New.php', '<?php');
        $zip->addFromString('votepit/bin/migrate.php', '<?php');
        $zip->addFromString('votepit/migrations/0000.sql', '-- x');
        $zip->close();
        $this->resignArchive($zipPath);

        $this->expectException(UpdateException::class);
        $this->expectExceptionMessage('does not contain the version');
        $this->runner()->run(null, false);
    }

    private function resignArchive(string $zipPath): void
    {
        $archive = basename($zipPath);
        file_put_contents($zipPath . '.sha256', hash_file('sha256', $zipPath) . "  {$archive}\n");
        file_put_contents($zipPath . '.minisig', MinisignFixture::sign((string) file_get_contents($zipPath), $this->key));
    }
}
