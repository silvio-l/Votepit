<?php

declare(strict_types=1);

namespace Votepit\Update;

/**
 * The self-update flow behind bin/update.php, with every side effect injected
 * so the decision logic is testable with temporary directories and test keys.
 *
 * Order, and where each step refuses:
 *   1. every trusted key must be a well-formed public key (placeholders refuse);
 *   2. the target release must exist and be newer than the installed version;
 *   3. archive and checksum are downloaded and the checksum must match;
 *   4. the minisign signature must verify against one of the trusted keys;
 *   5. the archive is extracted safely and must report the target version;
 *   6. the operator confirms the backup (bin/update.php, skipped with --yes);
 *   7. maintenance mode on, code swapped, migrations run;
 *   8. on migration failure the previous code is restored and maintenance stays on.
 *
 * Nothing is installed before step 4 passes. The workspace below storage/update/
 * is removed afterwards, except when a rollback failed: then it holds the only
 * complete copy of the previous code and is kept for manual recovery.
 */
final readonly class UpdateRunner
{
    public const STATUS_UP_TO_DATE = 'up_to_date';
    public const STATUS_AVAILABLE  = 'available';
    public const STATUS_UPDATED    = 'updated';
    public const STATUS_ABORTED    = 'aborted';

    /** Workspace below storage/ for downloads, the backup and rejected trees. */
    private const WORKSPACE = 'storage/update';

    /** Matches the checksum file's first token (sha256sum output). */
    private const SHA256_PATTERN = '/^[0-9a-f]{64}$/';

    /**
     * @param list<string>            $trustedKeys    base64 minisign public key lines
     * @param \Closure(): bool        $confirmBackup  true when the operator confirmed a backup
     * @param \Closure(string): bool  $runMigrations  runs the migrations of the installed tree, true on success
     * @param \Closure(string): void  $log            progress output
     */
    public function __construct(
        private string $root,
        private string $installedVersion,
        private CommunityReleaseClient $releases,
        private ReleaseHttp $http,
        private array $trustedKeys,
        private \Closure $confirmBackup,
        private \Closure $runMigrations,
        private \Closure $log,
    ) {}

    /**
     * @return string one of the STATUS_* constants
     * @throws UpdateException
     */
    public function run(?string $targetVersion, bool $checkOnly): string
    {
        $this->assertKeysUsable();

        $release = $targetVersion !== null
            ? $this->releases->byVersion($targetVersion)
            : $this->releases->latest();
        if (!$release instanceof \Votepit\Update\CommunityRelease) {
            throw new UpdateException('The release information could not be read from GitHub. Check the outbound HTTPS access of this server and try again.');
        }

        if ($release->version === $this->installedVersion) {
            return self::STATUS_UP_TO_DATE;
        }
        if (!SemanticVersion::isNewer($release->version, $this->installedVersion)) {
            throw new UpdateException("Version {$release->version} is older than the installed {$this->installedVersion}. Downgrades are refused.");
        }
        if ($checkOnly) {
            return self::STATUS_AVAILABLE;
        }

        $workspace = $this->root . '/' . self::WORKSPACE;
        $work      = $workspace . '/work-' . bin2hex(random_bytes(6));
        $this->makeDirectory($work);

        try {
            $tree = $this->fetchAndVerify($release, $work);

            if (InstalledVersion::fromRoot($tree) !== $release->version) {
                throw new UpdateException('The archive does not contain the version it is named after. Nothing was installed.');
            }

            if (!($this->confirmBackup)()) {
                self::removeTree($workspace);

                return self::STATUS_ABORTED;
            }

            $this->installTree($release->version, $tree, $workspace);
        } catch (RollbackFailedException $e) {
            throw $e; // keep the workspace: it holds the only complete copy of the old code
        } catch (\Throwable $e) {
            self::removeTree($workspace);

            throw $e;
        }

        self::removeTree($workspace);

        return self::STATUS_UPDATED;
    }

    /** @throws UpdateException */
    private function assertKeysUsable(): void
    {
        if ($this->trustedKeys === []) {
            throw new UpdateException('No release signing keys are configured. Refusing to update.');
        }
        foreach ($this->trustedKeys as $key) {
            if (!Minisign::isWellFormedPublicKey($key)) {
                throw new UpdateException('The release signing keys are not configured yet (placeholders in Votepit\Update\TrustedReleaseKeys). Refusing to update; see documentation/installation.md.');
            }
        }
    }

    /**
     * Steps 3 to 5: download, checksum, signature, safe extraction.
     *
     * @throws UpdateException
     */
    private function fetchAndVerify(CommunityRelease $release, string $work): string
    {
        $archive = CommunityEdition::archiveName($release->version);
        $zipUrl  = $release->assetUrl($archive);
        $shaUrl  = $release->assetUrl($archive . '.sha256');
        $sigUrl  = $release->assetUrl($archive . '.minisig');
        if ($zipUrl === null || $shaUrl === null || $sigUrl === null) {
            throw new UpdateException("Release {$release->version} has no complete set of assets (archive, checksum, signature).");
        }

        $zipPath = $work . '/' . $archive;
        $shaPath = $work . '/' . $archive . '.sha256';
        $sigPath = $work . '/' . $archive . '.minisig';

        ($this->log)("Downloading Votepit Community {$release->version}.");
        if (!$this->http->download($zipUrl, $zipPath, CommunityEdition::DOWNLOAD_TIMEOUT_SECONDS, CommunityEdition::MAX_DOWNLOAD_BYTES)
            || !$this->http->download($shaUrl, $shaPath, CommunityEdition::HTTP_TIMEOUT_SECONDS, 4096)
            || !$this->http->download($sigUrl, $sigPath, CommunityEdition::HTTP_TIMEOUT_SECONDS, 65536)
        ) {
            throw new UpdateException('The release files could not be downloaded. Nothing was installed.');
        }

        $shaLine = (string) file_get_contents($shaPath);
        $token   = strtolower((string) strtok(trim($shaLine), " \t"));
        $actual  = hash_file('sha256', $zipPath);
        if (preg_match(self::SHA256_PATTERN, $token) !== 1 || $actual === false || !hash_equals($token, $actual)) {
            throw new UpdateException('The archive checksum does not match. Nothing was installed.');
        }

        $bytes     = (string) file_get_contents($zipPath);
        $signature = (string) file_get_contents($sigPath);
        $verified  = false;
        foreach ($this->trustedKeys as $key) {
            if (Minisign::verify($bytes, $signature, $key)) {
                $verified = true;
                break;
            }
        }
        if (!$verified) {
            throw new UpdateException('The release signature does not verify against any trusted key. Nothing was installed.');
        }
        ($this->log)('Signature verified.');

        return ReleaseArchive::extract($zipPath, $work . '/extract');
    }

    /**
     * Steps 7 and 8.
     *
     * @throws UpdateException
     * @throws RollbackFailedException
     */
    private function installTree(string $version, string $tree, string $workspace): void
    {
        $maintenance = new MaintenanceMode($this->root);
        $swap        = new CodeSwap($this->root);
        $backup      = $workspace . '/backup-' . bin2hex(random_bytes(6));

        $maintenance->enable('Updating to ' . $version);
        ($this->log)('Maintenance mode on; swapping the code.');

        try {
            $swap->apply($tree, $backup);
        } catch (RollbackFailedException $e) {
            throw $e; // maintenance stays on: the site must not serve half-restored code
        } catch (UpdateException $e) {
            // apply() has restored the previous code; the site can serve again.
            $maintenance->disable();

            throw $e;
        }

        // The operator's config template follows the release; the operator's config.php never does.
        $template = $tree . '/config/config.example.php';
        if (is_file($template)) {
            @copy($template, $this->root . '/config/config.example.php');
        }

        ($this->log)('Running migrations.');
        if (!($this->runMigrations)($this->root)) {
            $swap->rollback($backup);

            throw new UpdateException(
                'The migrations failed. The previous code has been restored and maintenance mode stays ON, '
                . 'because the database may be partly migrated. Check the database against your backup, '
                . 'then remove ' . $maintenance->path() . ' to bring the site back.',
            );
        }

        $maintenance->disable();
        ($this->log)("Installed Votepit Community {$version}.");
    }

    /** @throws UpdateException */
    private function makeDirectory(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0o700, true) && !is_dir($dir)) {
            throw new UpdateException('The update workspace cannot be created in storage/.');
        }
    }

    /** Recursive delete that never follows symlinks. */
    public static function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            if ($item->isDir() && !$item->isLink()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($path);
    }
}
