<?php

declare(strict_types=1);

namespace Votepit\Tests\Migrations;

use PHPUnit\Framework\TestCase;
use Votepit\Migrations\SqlFileMigration;

/**
 * 0088_add_mcp_oauth: MySQL DDL, content-checked (the SQLite test schema in
 * IntegrationTestCase mirrors it and the OAuth integration tests run on it).
 * The security-relevant shape: only hashes are stored, everything hangs off
 * the client/grant with ON DELETE CASCADE, and an OAuth grant is an
 * api_tokens row marked by oauth_client_id.
 */
final class McpOAuthMigrationTest extends TestCase
{
    public function test_migration_creates_hash_only_oauth_tables(): void
    {
        $path = dirname(__DIR__, 2) . '/migrations/0088_add_mcp_oauth.sql';
        self::assertSame('0088_add_mcp_oauth', (new SqlFileMigration($path))->version());

        $sql = (string) file_get_contents($path);
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS oauth_clients', $sql);
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS oauth_authorization_codes', $sql);
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS oauth_tokens', $sql);
        self::assertStringContainsString('ADD COLUMN oauth_client_id BIGINT UNSIGNED NULL', $sql);
        self::assertStringContainsString('code_hash       CHAR(64)', $sql);
        self::assertStringContainsString('token_hash   CHAR(64)', $sql);
        self::assertStringContainsString("CHECK (kind IN ('access', 'refresh'))", $sql);
        self::assertStringNotContainsString('client_secret', $sql);
        self::assertSame(6, substr_count($sql, 'ON DELETE CASCADE'), 'grant FK + 4 code FKs + token FK');
    }
}
