<?php

declare(strict_types=1);

namespace Votepit\Tests;

use PHPUnit\Framework\TestCase;
use Votepit\DbConfig;

/**
 * OWASP/BSI audit remediation issue 08 (DB connection TLS): DbConfig's
 * ssl_ca parsing — the config key that opts a deployment into
 * ConnectionFactory setting PDO::MYSQL_ATTR_SSL_CA/
 * MYSQL_ATTR_SSL_VERIFY_SERVER_CERT (see ConnectionFactoryTest).
 */
final class DbConfigTest extends TestCase
{
    public function test_ssl_ca_defaults_to_null_when_absent(): void
    {
        $db = DbConfig::fromArray(['name' => 'votepit']);

        self::assertNull($db->sslCa);
    }

    public function test_ssl_ca_defaults_to_null_when_empty_string(): void
    {
        $db = DbConfig::fromArray(['name' => 'votepit', 'ssl_ca' => '']);

        self::assertNull($db->sslCa);
    }

    public function test_ssl_ca_is_trimmed_and_kept_when_set(): void
    {
        $db = DbConfig::fromArray(['name' => 'votepit', 'ssl_ca' => '  /etc/ssl/certs/db-ca.pem  ']);

        self::assertSame('/etc/ssl/certs/db-ca.pem', $db->sslCa);
    }
}
