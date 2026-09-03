<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
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
}
