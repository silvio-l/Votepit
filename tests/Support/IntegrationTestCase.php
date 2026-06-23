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
            'CREATE TABLE IF NOT EXISTS boards (
                id                 INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
                slug               VARCHAR(64) NOT NULL,
                name               VARCHAR(128) NOT NULL,
                accent_color       VARCHAR(16) NOT NULL DEFAULT \'#3b82f6\',
                primary_color      VARCHAR(7) NULL,
                secondary_color    VARCHAR(7) NULL,
                logo_url           VARCHAR(512) NULL,
                intro              TEXT NULL,
                moderation_enabled INTEGER NOT NULL DEFAULT 1,
                is_default         INTEGER NOT NULL DEFAULT 0,
                created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (slug)
            )',
        );

        $conn->executeStatement(
            'CREATE TABLE IF NOT EXISTS board_blocklist (
                id         INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
                board_id   INTEGER NOT NULL,
                word       VARCHAR(200) NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (board_id, word),
                FOREIGN KEY (board_id) REFERENCES boards(id) ON DELETE CASCADE
            )',
        );

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

        // Sprint 3: ideas-Tabelle (portables Subset; ohne FULLTEXT/ENGINE/UNSIGNED).
        $conn->executeStatement(
            'CREATE TABLE IF NOT EXISTS ideas (
                id               INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
                board_id         INTEGER NOT NULL,
                author_id        INTEGER NOT NULL,
                title            VARCHAR(200) NOT NULL,
                title_normalized VARCHAR(200) NOT NULL DEFAULT \'\',
                body             TEXT NOT NULL,
                status           VARCHAR(16) NOT NULL DEFAULT \'open\',
                is_pinned        INTEGER NOT NULL DEFAULT 0,
                merged_into_id   INTEGER NULL,
                score_cache      INTEGER NOT NULL DEFAULT 0,
                created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (board_id)  REFERENCES boards(id)  ON DELETE CASCADE,
                FOREIGN KEY (author_id) REFERENCES users(id)   ON DELETE RESTRICT
            )',
        );
    }

    // -------------------------------------------------------------------------
    // Sprint-3 Seed-Helfer
    // -------------------------------------------------------------------------

    /**
     * Seedet ein Board; liefert dessen ID.
     * Methode aus dem Sprint-3-Harness — nicht mit privaten seedBoard()-Methoden
     * in Unterklassen aus Sprint 2 kollidieren.
     *
     * @param array<string, mixed> $overrides
     */
    protected function insertBoard(string $slug = 'demo', array $overrides = []): int
    {
        $this->conn->insert('boards', array_merge([
            'slug'       => $slug,
            'name'       => 'Demo Board',
            'is_default' => 1,
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ], $overrides));

        return (int) $this->conn->lastInsertId();
    }

    /**
     * Seedet einen verifizierten User; liefert dessen ID.
     *
     * @param array<string, mixed> $overrides
     */
    protected function insertUser(string $email = 'user@example.com', array $overrides = []): int
    {
        $this->conn->insert('users', array_merge([
            'email'         => $email,
            'is_admin'      => 0,
            'is_blocked'    => 0,
            'token_version' => 0,
            'verified_at'   => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'created_at'    => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ], $overrides));

        return (int) $this->conn->lastInsertId();
    }

    /**
     * Seedet eine Idee; liefert deren ID.
     *
     * @param array<string, mixed> $overrides
     */
    protected function seedIdea(int $boardId, int $authorId, string $title = 'Test-Idee', array $overrides = []): int
    {
        $this->conn->insert('ideas', array_merge([
            'board_id'        => $boardId,
            'author_id'       => $authorId,
            'title'           => $title,
            'title_normalized' => mb_strtolower($title, 'UTF-8'),
            'body'            => 'Standardbeschreibung.',
            'status'          => 'open',
            'is_pinned'       => 0,
            'score_cache'     => 0,
            'created_at'      => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'updated_at'      => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ], $overrides));

        return (int) $this->conn->lastInsertId();
    }

    /**
     * Erzeugt ein gültiges signiertes Session-Cookie für den angegebenen User.
     */
    protected function sessionCookie(int $userId, int $tokenVersion = 0): string
    {
        $appKey   = str_repeat('a', 64);
        $sessions = new \Votepit\Security\SessionService($appKey, 3600, false);
        return $sessions->sign(['uid' => $userId, 'v' => $tokenVersion]);
    }
}
