<?php

declare(strict_types=1);

namespace Votepit\Tests\Support;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Slim\App;
use Votepit\Config;
use Votepit\Http\AppFactory;
use Votepit\Logging\AuditLogger;
use Votepit\Mail\InMemoryMailer;
use Votepit\Mail\Mailer;

/**
 * Test-DB-Harness (Sprint 2).
 *
 * Erzeugt pro Test eine frische SQLite-In-Memory-Verbindung und wendet ein
 * schlankes, SQLite-kompatibles Schema (users + login_tokens) an. Tests
 * booten die App über AppFactory::create($config, $conn, $mailer, $audit)
 * — identische HTTP-Seam wie die Produktion.
 *
 * SQLite statt MySQL: kein MySQL-Prozess notwendig; alle repositories nutzen
 * portables SQL (keine MySQL-spezifischen Funktionen). Die IP-Rate-Limit-Schicht
 * ist in Tests wirkungslos, weil REMOTE_ADDR im Test-Request nicht gesetzt ist
 * (RateLimitMiddleware gibt ohne Identität sofort durch).
 */
abstract class IntegrationTestCase extends TestCase
{
    protected Connection $conn;

    /** @var non-empty-string */
    protected string $logFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->conn    = $this->createSqliteConnection();
        $this->logFile = sys_get_temp_dir() . '/votepit-test-audit-' . uniqid() . '.log';

        $this->applySchema($this->conn);
    }

    protected function tearDown(): void
    {
        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }

        parent::tearDown();
    }

    /** @return App<null> */
    protected function createApp(?Mailer $mailer = null): App
    {
        $audit = new AuditLogger($this->logFile);
        // Default zu InMemoryMailer — kein echter SMTP-Versand in Tests.
        $resolvedMailer = $mailer ?? new InMemoryMailer();
        return AppFactory::create($this->testConfig(), $this->conn, $resolvedMailer, $audit);
    }

    protected function testConfig(): Config
    {
        return Config::fromArray([
            'env'             => 'dev',
            'app_url'         => 'http://localhost:8000',
            'app_key'         => str_repeat('a', 64),
            'db'              => ['name' => ':memory:'],
            'smtp'            => ['from_email' => 'noreply@example.com'],
            'magic_link_ttl'  => 900,
        ]);
    }

    /** Liest alle Zeilen der Audit-Log-Datei (für Assertions über Log-Inhalt). */
    protected function readAuditLog(): string
    {
        return is_file($this->logFile) ? (string) file_get_contents($this->logFile) : '';
    }

    private function createSqliteConnection(): Connection
    {
        return DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);
    }

    private function applySchema(Connection $conn): void
    {
        // SQLite-kompatibles Subset des MySQL-Schemas (db/schema.sql).
        // Ausgelassen: ENGINE, CHARSET, COLLATE, UNSIGNED, TINYINT(1),
        // FULLTEXT, ON DUPLICATE KEY UPDATE → nicht für Sprint-2-Tests nötig.
        $conn->executeStatement(
            'CREATE TABLE IF NOT EXISTS users (
                id            INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
                email         VARCHAR(254) NOT NULL,
                is_admin      INTEGER NOT NULL DEFAULT 0,
                is_blocked    INTEGER NOT NULL DEFAULT 0,
                token_version INTEGER NOT NULL DEFAULT 0,
                verified_at   DATETIME NULL,
                created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (email)
            )',
        );

        $conn->executeStatement(
            'CREATE TABLE IF NOT EXISTS login_tokens (
                id          INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
                user_id     INTEGER NOT NULL,
                token_hash  CHAR(64) NOT NULL,
                purpose     VARCHAR(32) NOT NULL DEFAULT \'login\',
                expires_at  DATETIME NOT NULL,
                used_at     DATETIME NULL,
                created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )',
        );

        $conn->executeStatement(
            'CREATE TABLE IF NOT EXISTS rate_limits (
                bucket            VARCHAR(128) NOT NULL,
                window_seconds    INTEGER NOT NULL,
                count             INTEGER NOT NULL DEFAULT 0,
                window_started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (bucket)
            )',
        );
    }
}
