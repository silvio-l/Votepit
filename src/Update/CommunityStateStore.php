<?php

declare(strict_types=1);

namespace Votepit\Update;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;

/**
 * Installation-wide state of the Community update channel, kept in the
 * existing app_settings key/value table (no schema of its own). Only the keys
 * listed here are ever read or written through this class.
 */
final readonly class CommunityStateStore
{
    public const LATEST_VERSION       = 'community.latest_version';
    public const CHECKED_AT           = 'community.checked_at';
    public const INSTALLATION_ID      = 'community.installation_id';
    public const VERSION_REPORTED_ON  = 'community.version_reported_on';

    public function __construct(private Connection $conn) {}

    /** @throws DbalException */
    public function get(string $key): ?string
    {
        $this->assertKnown($key);

        $value = $this->conn->fetchOne('SELECT value FROM app_settings WHERE `key` = :key', ['key' => $key]);

        return is_string($value) ? $value : null;
    }

    /** @throws DbalException */
    public function set(string $key, string $value): void
    {
        $this->assertKnown($key);

        if ($this->conn->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $this->conn->executeStatement(
                'INSERT INTO app_settings (`key`, value) VALUES (:key, :value) ON DUPLICATE KEY UPDATE value = :value2',
                ['key' => $key, 'value' => $value, 'value2' => $value],
            );

            return;
        }

        // SQLite-compatible (tests).
        $this->conn->executeStatement(
            'INSERT OR REPLACE INTO app_settings ("key", value) VALUES (:key, :value)',
            ['key' => $key, 'value' => $value],
        );
    }

    /**
     * Random installation ID, generated once and stored. 16 lowercase hex
     * characters, which is exactly the shape Matomo accepts as a visitor ID.
     *
     * @throws DbalException
     */
    public function installationId(): string
    {
        $existing = $this->get(self::INSTALLATION_ID);
        if ($existing !== null && preg_match('/^[0-9a-f]{16}\z/', $existing) === 1) {
            return $existing;
        }

        $id = bin2hex(random_bytes(8));
        $this->set(self::INSTALLATION_ID, $id);

        return $id;
    }

    private function assertKnown(string $key): void
    {
        if (!in_array($key, [self::LATEST_VERSION, self::CHECKED_AT, self::INSTALLATION_ID, self::VERSION_REPORTED_ON], true)) {
            throw new \InvalidArgumentException("Unknown community state key \"{$key}\".");
        }
    }
}
