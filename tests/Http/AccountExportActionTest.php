<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Config;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Integration tests for GET /admin/export (customer self-export —
 * GDPR Art. 20 data portability). Mirrors BillingActionTest's AuthZ-matrix
 * discipline plus a cross-tenant-leak test in the same spirit as the board read tests
 * (an export endpoint is exactly as dangerous a leak vector as a read
 * endpoint).
 */
final class AccountExportActionTest extends IntegrationTestCase
{
    /**
     * Low-but-safe 'account:export' limit (5/3600s) — every other test in
     * this class makes at most 2 export requests (test_csv_format_...),
     * well under this; only test_export_is_rate_limited_per_owner
     * deliberately exceeds it (review 2026-09-04, item 7).
     */
    protected function testConfig(): Config
    {
        return Config::fromArray([
            'env'                 => 'dev',
            'app_url'             => 'http://localhost:8000',
            'app_key'             => str_repeat('a', 64),
            'identity_server_key' => self::identityServerKey(),
            'db'                  => ['name' => ':memory:'],
            'smtp'                => ['from_email' => 'noreply@example.com'],
            'magic_link_ttl'      => 900,
            'rate_limits'         => [
                'account:export' => ['limit' => 5, 'window' => 3600],
            ],
        ]);
    }

    private function get(?int $actingUserId, ?string $format = null): ServerRequestInterface
    {
        $uri     = '/admin/export' . ($format !== null ? '?format=' . $format : '');
        $request = (new ServerRequestFactory())->createServerRequest('GET', $uri);

        if ($actingUserId !== null) {
            $request = $request->withCookieParams(['votepit_sess' => $this->sessionCookie($actingUserId)]);
        }

        return $request;
    }

    /**
     * Seeds a small but complete account: board (with branding), idea, vote,
     * comment, owner + moderator member, a pending invite, an API token, a
     * board SMTP config (with a fake encrypted password blob), a custom
     * moderation blocklist word, and an account-wide user block.
     *
     * @return array{account_id: int, owner_id: int, board_id: int, idea_id: int}
     */
    private function seedFullAccount(string $tag, ?int $accountId = null): array
    {
        $accountId ??= $this->insertAccount(['slug' => 'acct-' . $tag, 'name' => 'Account ' . $tag]);
        $ownerId     = $this->insertUser('owner-' . $tag . '@example.com');
        $modId     = $this->insertUser('mod-' . $tag . '@example.com');
        $this->insertAccountMember($accountId, $ownerId, 'owner');
        $this->insertAccountMember($accountId, $modId, 'moderator');

        $boardId = $this->insertBoard('board-' . $tag, [
            'account_id'  => $accountId,
            'name'        => 'Board ' . $tag,
            'intro'       => 'Intro text ' . $tag,
            'logo_url'    => 'https://example.com/logo-' . $tag . '.png',
        ]);

        $ideaId = $this->seedIdea($boardId, $ownerId, 'Idea-' . $tag);
        $this->seedVote($ideaId, $ownerId, 1);
        $this->seedComment($ideaId, $ownerId, 'Comment-' . $tag);

        $inviteeId = $this->insertUser('invitee-' . $tag . '@example.com');
        $this->insertInvite($accountId, $inviteeId, $ownerId, hash('sha256', 'invite-token-' . $tag));

        $this->insertApiToken($accountId, $boardId, $ownerId, hash('sha256', 'raw-token-' . $tag), 'ApiTokenLabel-' . $tag);

        $this->conn->insert('board_smtp_settings', [
            'board_id'    => $boardId,
            'host'        => 'smtp-' . $tag . '.example.com',
            'port'        => 587,
            'user'        => 'smtpuser-' . $tag,
            'pass'        => 'FAKE-ENCRYPTED-BLOB-' . $tag,
            'encryption'  => 'tls',
            'from_email'  => 'noreply-' . $tag . '@example.com',
            'from_name'   => 'Votepit ' . $tag,
            'verify_peer' => 1,
            'created_at'  => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'updated_at'  => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        $this->conn->insert('board_blocklist', [
            'board_id'   => $boardId,
            'word'       => 'blockword-' . $tag,
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        $blockedId = $this->insertUser('blocked-' . $tag . '@example.com');
        $this->conn->insert('blocked_users', [
            'account_id' => $accountId,
            'user_id'    => $blockedId,
            'board_id'   => null,
            'created_by' => $ownerId,
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        return ['account_id' => $accountId, 'owner_id' => $ownerId, 'board_id' => $boardId, 'idea_id' => $ideaId];
    }

    public function test_owner_can_export_full_account_data_as_json(): void
    {
        $seed = $this->seedFullAccount('a', $this->defaultAccountId());

        $app      = $this->createApp();
        $response = $app->handle($this->get($seed['owner_id']));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('attachment', $response->getHeaderLine('Content-Disposition'));

        $data = json_decode((string) $response->getBody(), true);
        self::assertIsArray($data);

        self::assertSame($seed['account_id'], $data['account']['id']);
        self::assertCount(1, $data['boards']);
        self::assertSame('board-a', $data['boards'][0]['slug']);
        self::assertCount(1, $data['ideas']);
        self::assertSame('Idea-a', $data['ideas'][0]['title']);
        self::assertCount(1, $data['votes']);
        self::assertCount(1, $data['comments']);
        self::assertSame('Comment-a', $data['comments'][0]['body']);
        self::assertCount(2, $data['members']); // owner + moderator
        self::assertCount(1, $data['invites']);
        self::assertCount(1, $data['api_tokens']);
        self::assertSame('ApiTokenLabel-a', $data['api_tokens'][0]['label']);
        self::assertCount(1, $data['board_smtp_settings']);
        self::assertSame('smtp-a.example.com', $data['board_smtp_settings'][0]['host']);
        self::assertCount(1, $data['moderation_blocklist_words']);
        self::assertCount(1, $data['blocked_users']);
        self::assertArrayHasKey('owner_notification_preferences', $data);
        self::assertCount(1, $data['owner_notification_preferences']);
    }

    public function test_export_includes_the_requesting_owners_own_notification_preferences(): void
    {
        $accountId = $this->defaultAccountId();
        $ownerId   = $this->insertUser('owner-notif@example.com', [
            'notification_email'        => 'owner-notif-inbox@example.com',
            'notify_idea_comment_email' => 1,
        ]);
        $this->insertAccountMember($accountId, $ownerId, 'owner');

        $app      = $this->createApp();
        $response = $app->handle($this->get($ownerId));

        $data = json_decode((string) $response->getBody(), true);
        self::assertIsArray($data);

        self::assertCount(1, $data['owner_notification_preferences']);
        $prefs = $data['owner_notification_preferences'][0];
        self::assertSame('owner-notif-inbox@example.com', $prefs['notification_email']);
        self::assertSame(1, (int) $prefs['notify_idea_comment_email']);
    }

    public function test_export_never_leaks_another_members_notification_email(): void
    {
        $accountId = $this->defaultAccountId();
        $ownerId   = $this->insertUser('owner-noleak@example.com');
        $modId     = $this->insertUser('mod-noleak@example.com', [
            'notification_email' => 'mod-secret-inbox@example.com',
        ]);
        $this->insertAccountMember($accountId, $ownerId, 'owner');
        $this->insertAccountMember($accountId, $modId, 'moderator');

        $app      = $this->createApp();
        $response = $app->handle($this->get($ownerId));

        $body = (string) $response->getBody();
        self::assertStringNotContainsString('mod-secret-inbox@example.com', $body);
    }

    public function test_cross_tenant_leak_export_never_contains_foreign_account_data(): void
    {
        $accountA = $this->seedFullAccount('leakA', $this->defaultAccountId());
        $this->seedFullAccount('leakB');

        $app      = $this->createApp();
        $response = $app->handle($this->get($accountA['owner_id']));

        self::assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();

        foreach (['leakB'] as $marker) {
            self::assertStringNotContainsString('board-' . $marker, $body);
            self::assertStringNotContainsString('Idea-' . $marker, $body);
            self::assertStringNotContainsString('Comment-' . $marker, $body);
            self::assertStringNotContainsString('ApiTokenLabel-' . $marker, $body);
            self::assertStringNotContainsString('smtp-' . $marker . '.example.com', $body);
            self::assertStringNotContainsString('blockword-' . $marker, $body);
        }

        $data = json_decode($body, true);
        self::assertCount(1, $data['boards']);
        self::assertCount(1, $data['ideas']);
        self::assertCount(1, $data['comments']);
        self::assertCount(1, $data['api_tokens']);
    }

    public function test_moderator_is_rejected(): void
    {
        $accountId = $this->defaultAccountId();
        $modId     = $this->insertUser('mod-reject@example.com');
        $this->insertAccountMember($accountId, $modId, 'moderator');

        $app      = $this->createApp();
        $response = $app->handle($this->get($modId));

        self::assertSame(403, $response->getStatusCode());
    }

    public function test_owner_of_other_account_is_rejected(): void
    {
        $otherAccountId = $this->insertAccount();
        $otherOwnerId   = $this->insertUser('other-owner@example.com');
        $this->insertAccountMember($otherAccountId, $otherOwnerId, 'owner');

        // Not a member of the default account being requested.
        $app      = $this->createApp();
        $response = $app->handle($this->get($otherOwnerId));

        self::assertSame(403, $response->getStatusCode());
    }

    public function test_anon_is_rejected(): void
    {
        $app      = $this->createApp();
        $response = $app->handle($this->get(null));

        self::assertSame(401, $response->getStatusCode());
    }

    public function test_member_and_secret_data_contains_no_pii_or_raw_secrets(): void
    {
        $seed = $this->seedFullAccount('pii', $this->defaultAccountId());

        $app      = $this->createApp();
        $response = $app->handle($this->get($seed['owner_id']));

        $body = (string) $response->getBody();

        // No plaintext email for any USER IDENTITY (owner/moderator/invitee/
        // blocked user) — none of these addresses are ever persisted in
        // plaintext anywhere (ADR 0002: only email_hmac is stored), so they
        // must never appear in the export either. This is deliberately NOT a
        // blanket "no @example.com anywhere" check: board_smtp_settings.
        // from_email is legitimate plaintext operational config the account
        // owner themselves configured (the sender address), stored in
        // plaintext in the DB already — excluding it from the export would
        // hide the owner's own configuration from their own GDPR export.
        foreach (['owner-pii@example.com', 'mod-pii@example.com', 'invitee-pii@example.com', 'blocked-pii@example.com'] as $identityEmail) {
            self::assertStringNotContainsString($identityEmail, $body);
        }

        $data = json_decode($body, true);

        foreach ($data['members'] as $member) {
            self::assertArrayHasKey('user_id', $member);
            self::assertArrayHasKey('role', $member);
            self::assertArrayNotHasKey('email', $member);
            self::assertArrayNotHasKey('email_hmac', $member);
        }

        foreach ($data['api_tokens'] as $token) {
            self::assertArrayNotHasKey('token_hash', $token);
        }
        self::assertStringNotContainsString(hash('sha256', 'raw-token-pii'), $body);
        self::assertStringNotContainsString('raw-token-pii', $body);

        foreach ($data['board_smtp_settings'] as $smtp) {
            self::assertArrayNotHasKey('pass', $smtp);
        }
        self::assertStringNotContainsString('FAKE-ENCRYPTED-BLOB-pii', $body);
    }

    /**
     * Schema-drift guard: for every account-scoped export entity, every
     * live DB column must show up either in the exported row's keys or in
     * this test's explicit, reasoned exclusion list. A migration that adds
     * a column to one of these tables (e.g. comments.edited_at, added by
     * migrations/0027_add_comment_edit_window.sql and initially missed by
     * CommentRepository::listForAccount()) now fails THIS test instead of
     * silently vanishing from the customer's GDPR export — composer qa
     * (which includes this suite) runs on every push (root .githooks/
     * pre-push), so drift is caught before it ever reaches staging/prod.
     *
     * Uses live schema introspection (Doctrine\DBAL\Connection::
     * createSchemaManager(), same abstraction the app already depends on)
     * rather than a hand-copied column list, so the check itself cannot
     * drift out of sync with the schema the way a second manually
     * maintained list could.
     *
     * `owner_notification_preferences` is deliberately NOT checked here:
     * it is a narrow, five-column personal-data projection off the much
     * larger `users` table (see AccountExportService's class doc), not a
     * "mirror this table" export — the same blanket check would need to
     * enumerate dozens of legitimately-excluded security/PII columns
     * (password_hash, totp_secret_encrypted, email_hmac, ...) and would
     * itself become the thing that drifts.
     */
    public function test_exported_entities_cover_every_column_of_their_source_table(): void
    {
        $seed = $this->seedFullAccount('drift', $this->defaultAccountId());

        $app      = $this->createApp();
        $response = $app->handle($this->get($seed['owner_id']));
        $data     = json_decode((string) $response->getBody(), true);
        self::assertIsArray($data);

        $schemaManager = $this->conn->createSchemaManager();

        /**
         * entity key => [table name, allowed-excluded columns => reason].
         *
         * @var array<string, array{0: string, 1: array<string, string>}>
         */
        $checks = [
            'account' => ['accounts', []],
            'boards' => ['boards', [
                'account_id' => 'implicit — export is already account-scoped',
            ]],
            'ideas' => ['ideas', []],
            'votes' => ['votes', []],
            'comments' => ['comments', []],
            'members' => ['account_members', [
                'account_id' => 'implicit — the whole export is already scoped to one account',
            ]],
            'invites' => ['invites', [
                'account_id' => 'implicit — export is already account-scoped',
                'token_hash' => 'secret — plaintext token is only ever shown once at send time',
            ]],
            'api_tokens' => ['api_tokens', [
                'account_id' => 'implicit — export is already account-scoped',
                'token_hash' => 'secret — plaintext token is only ever shown once at creation time',
            ]],
            'board_smtp_settings' => ['board_smtp_settings', [
                'id' => 'internal PK — board_id is already the natural (unique) key',
                'pass' => 'secret — SMTP password (encrypted at rest), never exported plaintext or otherwise',
            ]],
            'moderation_blocklist_words' => ['board_blocklist', [
                'id' => 'internal PK — (board_id, word) is already the natural key',
            ]],
            'blocked_users' => ['blocked_users', [
                'account_id' => 'implicit — export is already account-scoped',
            ]],
        ];

        foreach ($checks as $entityKey => [$table, $allowedExclusions]) {
            $dbColumns = array_map(
                static fn (\Doctrine\DBAL\Schema\Column $col): string => $col->getName(),
                $schemaManager->listTableColumns($table),
            );

            $exportedRow = $entityKey === 'account' ? $data['account'] : ($data[$entityKey][0] ?? null);
            self::assertIsArray($exportedRow, "no exported sample row for entity '{$entityKey}' — seedFullAccount() must seed at least one");
            $exportedKeys = array_keys($exportedRow);

            $missing = array_values(array_diff($dbColumns, $exportedKeys, array_keys($allowedExclusions)));

            self::assertSame(
                [],
                $missing,
                "Column(s) [" . implode(', ', $missing) . "] exist on table '{$table}' but are neither "
                . "exported under '{$entityKey}' nor listed as a deliberate exclusion in this test — "
                . 'either add them to the export or add a reasoned exclusion here.',
            );
        }
    }

    public function test_csv_format_returns_zip_with_equivalent_data(): void
    {
        $seed = $this->seedFullAccount('csv', $this->defaultAccountId());

        $app         = $this->createApp();
        $jsonResp    = $app->handle($this->get($seed['owner_id']));
        $csvResponse = $app->handle($this->get($seed['owner_id'], 'csv'));

        self::assertSame(200, $csvResponse->getStatusCode());
        self::assertSame('application/zip', $csvResponse->getHeaderLine('Content-Type'));
        self::assertStringContainsString('.zip', $csvResponse->getHeaderLine('Content-Disposition'));

        $zipBytes = (string) $csvResponse->getBody();
        $tmpFile  = tempnam(sys_get_temp_dir(), 'votepit-export-test-');
        self::assertIsString($tmpFile);
        file_put_contents($tmpFile, $zipBytes);

        $zip = new \ZipArchive();
        self::assertTrue($zip->open($tmpFile) === true);

        $expectedFiles = [
            'meta.csv', 'account.csv', 'boards.csv', 'ideas.csv', 'votes.csv',
            'comments.csv', 'members.csv', 'invites.csv', 'api_tokens.csv',
            'board_smtp_settings.csv', 'moderation_blocklist_words.csv', 'blocked_users.csv',
            'owner_notification_preferences.csv',
        ];
        foreach ($expectedFiles as $file) {
            self::assertNotFalse($zip->locateName($file), "missing $file in export zip");
        }

        $ideasCsv  = (string) $zip->getFromName('ideas.csv');
        $ideaLines = array_values(array_filter(explode("\n", trim($ideasCsv)), static fn (string $line): bool => $line !== ''));

        $jsonData = json_decode((string) $jsonResp->getBody(), true);
        // header + 1 data row for the single seeded idea.
        self::assertCount(count($jsonData['ideas']) + 1, $ideaLines);
        self::assertStringContainsString('Idea-csv', $ideasCsv);

        $tokensCsv = (string) $zip->getFromName('api_tokens.csv');
        self::assertStringNotContainsString('token_hash', $tokensCsv);

        $smtpCsv = (string) $zip->getFromName('board_smtp_settings.csv');
        self::assertStringNotContainsString('FAKE-ENCRYPTED-BLOB-csv', $smtpCsv);

        $zip->close();
        unlink($tmpFile);
    }

    public function test_export_is_rate_limited_per_owner(): void
    {
        $seed = $this->seedFullAccount('rl', $this->defaultAccountId());
        $app  = $this->createApp();

        // Config default: 5/3600s (see testConfig() override above).
        $statusCodes = [];
        for ($i = 0; $i < 6; $i++) {
            $statusCodes[] = $app->handle($this->get($seed['owner_id']))->getStatusCode();
        }

        self::assertSame([200, 200, 200, 200, 200, 429], $statusCodes);
    }
}
