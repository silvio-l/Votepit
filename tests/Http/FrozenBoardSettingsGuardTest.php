<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Security\CsrfService;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Owner rule (2026-10-22, answer "1b") for FROZEN boards — either freeze
 * reason (`frozen_at` = plan downgrade, `compliance_frozen_at` = missing
 * operator details):
 *
 *   - every board-settings write is rejected with 423 `board_frozen`
 *     (FrozenBoardGuard::reject) and writes nothing;
 *   - the single allowed write is switching visibility TO 'private'
 *     (see FrozenBoardBrandingTest);
 *   - deleting the board stays allowed (data minimisation, owner right);
 *   - the operator-acknowledgment POST stays allowed: it is the remedy for
 *     `compliance_frozen_at`, not a setting (BoardComplianceEnforcer lifts
 *     the freeze after it), so blocking it would make a compliance freeze
 *     permanent.
 */
final class FrozenBoardSettingsGuardTest extends IntegrationTestCase
{
    private const SLUG = 'frozen-board';

    /** @return array<string, array{string}> */
    public static function freezeReasons(): array
    {
        return [
            'downgrade freeze (frozen_at)'          => ['frozen_at'],
            'compliance freeze (compliance_frozen_at)' => ['compliance_frozen_at'],
        ];
    }

    /**
     * Every board-settings write that must answer 423 on a frozen board,
     * crossed with both freeze reasons.
     *
     * @return iterable<string, array{string, string, array<string, mixed>, string}>
     */
    public static function frozenSettingsWrites(): iterable
    {
        $slug = self::SLUG;
        $writes = [
            'branding POST (colour)'       => ['POST', "/admin/boards/{$slug}/branding", ['primary_color' => '#123456']],
            'rename PUT'                   => ['PUT', "/admin/boards/{$slug}", ['name' => 'Renamed']],
            'status create'                => ['POST', "/admin/boards/{$slug}/statuses", ['category' => 'done', 'label_de' => 'X']],
            'status update'                => ['PUT', "/admin/boards/{$slug}/statuses/planned", ['label_de' => 'Bald']],
            'status delete'                => ['POST', "/admin/boards/{$slug}/statuses/planned/delete", []],
            'phases POST'                  => ['POST', "/admin/boards/{$slug}/phases", []],
            'moderation settings POST'     => ['POST', "/admin/boards/{$slug}/moderation", ['action' => 'toggle', 'enabled' => '1']],
            'smtp PUT'                     => ['PUT', "/admin/boards/{$slug}/smtp", ['host' => 'smtp.example.com', 'port' => 587]],
            'webhook PUT'                  => ['PUT', "/admin/boards/{$slug}/webhook", ['url' => 'https://example.com/hook', 'secret' => 'a-long-enough-webhook-secret-0123456789']],
            'webhook PATCH'                => ['PATCH', "/admin/boards/{$slug}/webhook", ['active' => false]],
            'webhook DELETE'               => ['DELETE', "/admin/boards/{$slug}/webhook", []],
            'chat notification PUT'        => ['PUT', "/admin/boards/{$slug}/chat-notifications/slack", ['url' => 'https://hooks.slack.com/services/T0001/B0002/supersecrettoken']],
            'chat notification DELETE'     => ['DELETE', "/admin/boards/{$slug}/chat-notifications/slack", []],
            'widget origins PUT'           => ['PUT', "/admin/boards/{$slug}/widget", ['allowed_origins' => ['https://example.com']]],
            'widget secret rotate'         => ['POST', "/admin/boards/{$slug}/widget/secret", []],
            'pro branding POST'            => ['POST', "/admin/boards/{$slug}/branding/pro", ['intro' => 'Hallo']],
            'asset upload cover'           => ['POST', "/admin/boards/{$slug}/assets/cover", []],
            'asset upload favicon'         => ['POST', "/admin/boards/{$slug}/assets/favicon", []],
            'asset upload og'              => ['POST', "/admin/boards/{$slug}/assets/og", []],
            'asset delete cover'           => ['POST', "/admin/boards/{$slug}/assets/cover/delete", []],
            'link footer PUT'              => ['PUT', "/admin/boards/{$slug}/link-footer", ['back_link_url' => 'https://example.com', 'back_link_label' => 'Zurück', 'footer_note' => 'Notiz']],  // export-ok: comment-language
        ];

        foreach ($writes as $label => [$method, $path, $body]) {
            foreach (self::freezeReasons() as $reasonLabel => [$column]) {
                yield "{$label} / {$reasonLabel}" => [$method, $path, $body, $column];
            }
        }
    }

    private function makeOwner(): int
    {
        $ownerId = $this->insertUser('frozen-owner@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');

        return $ownerId;
    }

    /** @param array<string, mixed> $body */
    private function request(string $method, string $path, int $userId, array $body = []): ServerRequestInterface
    {
        $csrf  = new CsrfService(str_repeat('a', 64), 3600, false);
        $token = $csrf->generate();

        return (new ServerRequestFactory())
            ->createServerRequest($method, $path)
            ->withCookieParams([
                $csrf->cookieName() => $csrf->sign($token),
                'votepit_sess'       => $this->sessionCookie($userId),
            ])
            ->withParsedBody(array_merge(['_csrf' => $token], $body));
    }

    /** @return array<string, mixed> */
    private function boardRow(): array
    {
        $row = $this->conn->fetchAssociative('SELECT * FROM boards WHERE slug = :slug', ['slug' => self::SLUG]);
        self::assertIsArray($row);

        return $row;
    }

    /** @param array<string, mixed> $body */
    #[DataProvider('frozenSettingsWrites')]
    public function test_settings_write_is_rejected_with_423_and_changes_nothing(string $method, string $path, array $body, string $freezeColumn): void
    {
        $ownerId = $this->makeOwner();
        $this->insertBoard(self::SLUG, [$freezeColumn => (new \DateTimeImmutable())->format('Y-m-d H:i:s')]);
        $before = $this->boardRow();

        $response = $this->createApp()->handle($this->request($method, $path, $ownerId, $body));

        self::assertSame(423, $response->getStatusCode(), (string) $response->getBody());
        $decoded = json_decode((string) $response->getBody(), true);
        self::assertSame('board_frozen', $decoded['error']['key'] ?? null);
        self::assertSame($before, $this->boardRow());
    }

    /** Rename keeps its own guard; asserted explicitly so the owner rule covers it. */
    public function test_rename_on_frozen_board_keeps_name_and_slug(): void
    {
        $ownerId = $this->makeOwner();
        $this->insertBoard(self::SLUG, ['frozen_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s')]);

        $response = $this->createApp()->handle($this->request('PUT', '/admin/boards/' . self::SLUG, $ownerId, ['name' => 'Renamed']));

        self::assertSame(423, $response->getStatusCode());
        self::assertSame('Demo Board', $this->boardRow()['name']);
    }

    public function test_issue_link_create_is_rejected_on_frozen_board(): void
    {
        $ownerId = $this->makeOwner();
        $boardId = $this->insertBoard(self::SLUG, ['frozen_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s')]);
        $ideaId  = $this->seedIdea($boardId, $ownerId);

        $response = $this->createApp()->handle($this->request(
            'POST',
            "/admin/boards/" . self::SLUG . "/ideas/{$ideaId}/issue-links/github",
            $ownerId,
            [],
        ));

        self::assertSame(423, $response->getStatusCode());
        self::assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM idea_issue_links'));
    }

    public function test_issue_link_delete_is_rejected_on_frozen_board(): void
    {
        $ownerId = $this->makeOwner();
        $boardId = $this->insertBoard(self::SLUG, ['frozen_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s')]);
        $ideaId  = $this->seedIdea($boardId, $ownerId);

        $response = $this->createApp()->handle($this->request(
            'DELETE',
            "/admin/boards/" . self::SLUG . "/ideas/{$ideaId}/issue-links/github",
            $ownerId,
        ));

        self::assertSame(423, $response->getStatusCode());
    }

    public function test_owner_can_still_delete_a_frozen_board(): void
    {
        $ownerId = $this->makeOwner();
        $this->insertBoard(self::SLUG, ['frozen_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s')]);

        $response = $this->createApp()->handle($this->request(
            'POST',
            '/admin/boards/' . self::SLUG . '/delete',
            $ownerId,
            ['confirm_slug' => self::SLUG],
        ));

        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($this->conn->fetchOne('SELECT id FROM boards WHERE slug = :slug', ['slug' => self::SLUG]));
    }

    public function test_operator_acknowledgment_stays_open_on_a_compliance_frozen_board(): void
    {
        $ownerId = $this->makeOwner();
        $this->insertBoard(self::SLUG, ['compliance_frozen_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s')]);

        $response = $this->createApp()->handle($this->request(
            'POST',
            '/admin/boards/' . self::SLUG . '/operator-acknowledgment',
            $ownerId,
            ['operator_acknowledged' => '1'],
        ));

        self::assertNotSame(423, $response->getStatusCode(), 'the acknowledgment is the remedy for a compliance freeze');
    }

    public function test_user_block_stays_open_on_a_frozen_board(): void
    {
        $ownerId  = $this->makeOwner();
        $this->insertBoard(self::SLUG, ['frozen_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s')]);
        $spammer  = $this->insertUser('frozen-spammer@example.com');

        $response = $this->createApp()->handle($this->request(
            'POST',
            '/admin/boards/' . self::SLUG . '/block',
            $ownerId,
            ['user_id' => (string) $spammer, 'blocked' => '1'],
        ));

        self::assertNotSame(423, $response->getStatusCode(), 'takedown/safety actions must stay possible on a read-only board');
    }
}
