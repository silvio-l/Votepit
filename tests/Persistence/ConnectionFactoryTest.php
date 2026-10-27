<?php

declare(strict_types=1);

namespace Votepit\Tests\Persistence;

use PHPUnit\Framework\TestCase;
use Votepit\DbConfig;
use Votepit\Persistence\ConnectionFactory;

/**
 * OWASP/BSI audit remediation issue 08 (DB connection TLS).
 *
 * Doctrine DBAL's DriverManager::getConnection() is lazy — constructing a
 * Connection does not open a socket, so these assertions inspect the built
 * driverOptions (via getParams()) rather than requiring a live MySQL server.
 * That proves the option is actually wired through DbConfig -> DriverManager
 * config, not just declared somewhere. The complementary claim — that
 * PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT really enforces certificate
 * verification against a real TLS handshake, rather than silently no-op-ing
 * — was verified separately against a real MySQL/TLS fixture (see the issue
 * file's "Implementierungsstand" notes); no CI-portable MySQL+TLS fixture
 * exists in this repo yet, so that verification isn't automated here.
 */
final class ConnectionFactoryTest extends TestCase
{
    public function test_no_ssl_ca_means_no_tls_driver_options(): void
    {
        $db            = DbConfig::fromArray(['name' => 'votepit']);
        $driverOptions = $this->driverOptionsOf($db);

        self::assertArrayNotHasKey(\PDO::MYSQL_ATTR_SSL_CA, $driverOptions);
        self::assertArrayNotHasKey(\PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT, $driverOptions);
    }

    public function test_ssl_ca_enables_encryption_and_verification_together(): void
    {
        $db            = DbConfig::fromArray(['name' => 'votepit', 'ssl_ca' => '/etc/ssl/certs/db-ca.pem']);
        $driverOptions = $this->driverOptionsOf($db);

        // Never encryption without verification (MITM-vulnerable) — the two
        // options are set together or not at all.
        self::assertSame('/etc/ssl/certs/db-ca.pem', $driverOptions[\PDO::MYSQL_ATTR_SSL_CA]);
        self::assertTrue($driverOptions[\PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT]);
    }

    public function test_base_driver_options_are_preserved_regardless_of_ssl_ca(): void
    {
        $db            = DbConfig::fromArray(['name' => 'votepit', 'ssl_ca' => '/etc/ssl/certs/db-ca.pem']);
        $driverOptions = $this->driverOptionsOf($db);

        self::assertSame(\PDO::ERRMODE_EXCEPTION, $driverOptions[\PDO::ATTR_ERRMODE]);
        self::assertFalse($driverOptions[\PDO::ATTR_EMULATE_PREPARES]);
        self::assertSame(\PDO::FETCH_ASSOC, $driverOptions[\PDO::ATTR_DEFAULT_FETCH_MODE]);
    }

    /** @return array<int, mixed> */
    private function driverOptionsOf(DbConfig $db): array
    {
        $params = ConnectionFactory::createForDb($db)->getParams();
        self::assertArrayHasKey('driverOptions', $params);
        /** @var array<int, mixed> $driverOptions */
        $driverOptions = $params['driverOptions'];

        return $driverOptions;
    }
}
