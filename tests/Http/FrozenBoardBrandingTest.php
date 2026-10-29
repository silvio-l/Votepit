<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Security\CsrfService;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * BoardBrandingAction on a frozen board (owner rule 2026-10-22, "1b"): the
 * only accepted write is a request whose sole field is visibility='private'.
 * Anything else — another field, visibility public/unlisted, or an empty
 * body — is 423 board_frozen and writes nothing. The freeze itself is never
 * lifted by this endpoint.
 */
final class FrozenBoardBrandingTest extends IntegrationTestCase
{
    private const SLUG = 'frozen-branding';

    /** @return array<string, array{string}> */
    public static function freezeReasons(): array
    {
        return [
            'downgrade freeze (frozen_at)'             => ['frozen_at'],
            'compliance freeze (compliance_frozen_at)' => ['compliance_frozen_at'],
        ];
    }

    private function makeOwner(): int
    {
        $ownerId = $this->insertUser('branding-owner@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');

        return $ownerId;
    }

    /** @param array<string, mixed> $body */
    private function post(int $userId, array $body): ServerRequestInterface
    {
        $csrf  = new CsrfService(str_repeat('a', 64), 3600, false);
        $token = $csrf->generate();

        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/admin/boards/' . self::SLUG . '/branding')
            ->withCookieParams([
                $csrf->cookieName() => $csrf->sign($token),
                'votepit_sess'       => $this->sessionCookie($userId),
            ])
            ->withParsedBody(array_merge(['_csrf' => $token], $body));
    }

    private function freeze(string $column): void
    {
        $this->insertBoard(self::SLUG, [
            'visibility' => 'public',
            $column      => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    private function visibility(): string
    {
        return (string) $this->conn->fetchOne('SELECT visibility FROM boards WHERE slug = :slug', ['slug' => self::SLUG]);
    }

    private function frozenAt(string $column): ?string
    {
        $value = $this->conn->fetchOne("SELECT {$column} FROM boards WHERE slug = :slug", ['slug' => self::SLUG]);

        return $value === false || $value === null ? null : (string) $value;
    }

    #[DataProvider('freezeReasons')]
    public function test_making_a_frozen_board_private_is_allowed_and_keeps_the_freeze(string $column): void
    {
        $ownerId = $this->makeOwner();
        $this->freeze($column);

        $response = $this->createApp()->handle($this->post($ownerId, ['visibility' => 'private']));

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('private', $this->visibility());
        self::assertNotNull($this->frozenAt($column), 'the write must not lift the freeze');
    }

    #[DataProvider('freezeReasons')]
    public function test_visibility_private_plus_another_field_is_rejected(string $column): void
    {
        $ownerId = $this->makeOwner();
        $this->freeze($column);

        $response = $this->createApp()->handle($this->post($ownerId, [
            'visibility'    => 'private',
            'primary_color' => '#123456',
        ]));

        self::assertSame(423, $response->getStatusCode());
        self::assertSame('public', $this->visibility());
    }

    /** @return array<string, array{string, string}> */
    public static function nonPrivateVisibilities(): array
    {
        $out = [];
        foreach (self::freezeReasons() as $reasonLabel => [$column]) {
            $out["public / {$reasonLabel}"]   = ['public', $column];
            $out["unlisted / {$reasonLabel}"] = ['unlisted', $column];
        }

        return $out;
    }

    #[DataProvider('nonPrivateVisibilities')]
    public function test_visibility_other_than_private_is_rejected(string $visibility, string $column): void
    {
        $ownerId = $this->makeOwner();
        $this->insertBoard(self::SLUG, ['visibility' => 'private', $column => (new \DateTimeImmutable())->format('Y-m-d H:i:s')]);

        $response = $this->createApp()->handle($this->post($ownerId, ['visibility' => $visibility]));

        self::assertSame(423, $response->getStatusCode());
        self::assertSame('private', $this->visibility());
    }

    #[DataProvider('freezeReasons')]
    public function test_empty_write_is_rejected(string $column): void
    {
        $ownerId = $this->makeOwner();
        $this->freeze($column);

        $response = $this->createApp()->handle($this->post($ownerId, []));

        self::assertSame(423, $response->getStatusCode());
    }

    #[DataProvider('freezeReasons')]
    public function test_translation_toggle_is_rejected_on_frozen_board(string $column): void
    {
        $ownerId = $this->makeOwner();
        $this->freeze($column);

        $response = $this->createApp()->handle($this->post($ownerId, ['translation_enabled' => '1']));

        self::assertSame(423, $response->getStatusCode());
        self::assertSame(0, (int) $this->conn->fetchOne('SELECT translation_enabled FROM boards WHERE slug = :slug', ['slug' => self::SLUG]));
    }

    public function test_unfrozen_board_is_not_affected_by_the_guard(): void
    {
        $ownerId = $this->makeOwner();
        $this->insertBoard(self::SLUG, ['visibility' => 'public']);

        $response = $this->createApp()->handle($this->post($ownerId, ['visibility' => 'private']));

        self::assertSame(200, $response->getStatusCode(), 'control: an unfrozen board accepts the same write');
        self::assertSame('private', $this->visibility());
    }
}
