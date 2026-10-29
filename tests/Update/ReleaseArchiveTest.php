<?php

declare(strict_types=1);

namespace Votepit\Tests\Update;

use PHPUnit\Framework\TestCase;
use Votepit\Update\ReleaseArchive;
use Votepit\Update\UpdateException;
use Votepit\Update\UpdateRunner;

final class ReleaseArchiveTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/votepit-archive-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o700, true);
    }

    protected function tearDown(): void
    {
        UpdateRunner::removeTree($this->dir);
    }

    /** @param array<string, string> $entries */
    private function zip(array $entries): string
    {
        $path = $this->dir . '/release.zip';
        $zip  = new \ZipArchive();
        self::assertTrue($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        return $path;
    }

    /** @return array<string, string> */
    private function completeRelease(): array
    {
        return [
            'votepit/composer.json' => '{"version":"0.1.8"}',
            'votepit/vendor/autoload.php' => '<?php',
            'votepit/public/index.php' => '<?php',
            'votepit/public/index.html' => '<html></html>',
            'votepit/src/Kernel.php' => '<?php',
            'votepit/bin/migrate.php' => '<?php',
            'votepit/migrations/0000_baseline.sql' => '-- sql',
        ];
    }

    public function test_a_complete_release_is_extracted_to_its_tree(): void
    {
        $tree = ReleaseArchive::extract($this->zip($this->completeRelease()), $this->dir . '/out');

        self::assertSame($this->dir . '/out/votepit', $tree);
        self::assertFileExists($tree . '/src/Kernel.php');
    }

    public function test_traversal_and_absolute_paths_are_refused(): void
    {
        foreach (['votepit/../evil.php', '/votepit/evil.php', 'votepit/a\\..\\evil.php', 'other/evil.php'] as $name) {
            $entries = $this->completeRelease() + [$name => 'x'];

            try {
                ReleaseArchive::extract($this->zip($entries), $this->dir . '/out-' . md5($name));
                self::fail("unsafe entry {$name} was accepted");
            } catch (UpdateException $e) {
                self::assertStringContainsString('unsafe entry path', $e->getMessage());
            }
        }
    }

    public function test_an_archive_carrying_the_installed_config_is_refused(): void
    {
        $this->expectException(UpdateException::class);
        $this->expectExceptionMessage('unsafe entry path');

        ReleaseArchive::extract($this->zip($this->completeRelease() + ['votepit/config/config.php' => '<?php']), $this->dir . '/out');
    }

    public function test_an_incomplete_release_is_refused(): void
    {
        $entries = $this->completeRelease();
        unset($entries['votepit/bin/migrate.php']);

        $this->expectException(UpdateException::class);
        $this->expectExceptionMessage('bin/migrate.php is missing');

        ReleaseArchive::extract($this->zip($entries), $this->dir . '/out');
    }
}
