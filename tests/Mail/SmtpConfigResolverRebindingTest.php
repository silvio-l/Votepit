<?php

declare(strict_types=1);

namespace Votepit\Tests\Mail;

use Votepit\Mail\SmtpConfigResolver;
use Votepit\Persistence\BoardSmtpSettingsRepository;
use Votepit\Persistence\SmtpSettingsRepository;
use Votepit\Security\EncryptionService;
use Votepit\Security\SmtpHostPolicy;
use Votepit\SmtpConfig;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Security review — DNS rebinding TOCTOU: a board/global SMTP relay is
 * checked against SmtpHostPolicy only in BoardSmtpAction::save(). Without a
 * re-check on every `resolve()`, an initially public hostname could later be
 * redirected via DNS to an internal target without any further send
 * noticing.
 */
final class SmtpConfigResolverRebindingTest extends IntegrationTestCase
{
    private function encryptionSvc(): EncryptionService
    {
        return new EncryptionService(str_repeat('a', 64));
    }

    private function insertBoardSmtp(int $boardId, string $host): void
    {
        $this->conn->insert('board_smtp_settings', [
            'board_id'   => $boardId,
            'host'       => $host,
            'port'       => 587,
            'user'       => '',
            'pass'       => null,
            'encryption' => 'tls',
            'from_email' => 'board@example.com',
            'from_name'  => 'Board',
            'verify_peer' => 1,
        ]);
    }

    public function test_resolve_throws_when_a_previously_saved_board_relay_no_longer_resolves_public(): void
    {
        $boardId = $this->insertBoard();
        $this->insertBoardSmtp($boardId, 'relay.example.com');

        // Simulates DNS rebinding: the host policy now resolves the same
        // name to a private IP (it was public at save time).
        $rebindingPolicy = new SmtpHostPolicy(
            true,
            static fn (string $host): array => $host === 'relay.example.com' ? ['10.0.0.5'] : [],
        );

        $resolver = new SmtpConfigResolver(
            new SmtpSettingsRepository($this->conn),
            new BoardSmtpSettingsRepository($this->conn),
            $this->encryptionSvc(),
            SmtpConfig::fromArray(['host' => 'fallback.example.com', 'port' => 587, 'from_email' => 'a@b.c']),
            $rebindingPolicy,
        );

        $this->expectException(\Votepit\ConfigException::class);
        $resolver->resolve($boardId);
    }

    public function test_resolve_returns_board_config_when_host_still_resolves_public(): void
    {
        $boardId = $this->insertBoard();
        $this->insertBoardSmtp($boardId, 'relay.example.com');

        $policy = new SmtpHostPolicy(
            true,
            static fn (string $host): array => $host === 'relay.example.com' ? ['203.0.113.10'] : [],
        );

        $resolver = new SmtpConfigResolver(
            new SmtpSettingsRepository($this->conn),
            new BoardSmtpSettingsRepository($this->conn),
            $this->encryptionSvc(),
            SmtpConfig::fromArray(['host' => 'fallback.example.com', 'port' => 587, 'from_email' => 'a@b.c']),
            $policy,
        );

        self::assertSame('relay.example.com', $resolver->resolve($boardId)->host);
    }

    public function test_self_host_mode_never_re_validates(): void
    {
        $boardId = $this->insertBoard();
        $this->insertBoardSmtp($boardId, 'localhost');

        $resolver = new SmtpConfigResolver(
            new SmtpSettingsRepository($this->conn),
            new BoardSmtpSettingsRepository($this->conn),
            $this->encryptionSvc(),
            SmtpConfig::fromArray(['host' => 'fallback.example.com', 'port' => 587, 'from_email' => 'a@b.c']),
            new SmtpHostPolicy(false),
        );

        self::assertSame('localhost', $resolver->resolve($boardId)->host);
    }
}
