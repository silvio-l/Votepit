<?php

declare(strict_types=1);

namespace Votepit\Tests\Mail;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Transport\Dsn;
use Votepit\Mail\SymfonyMailerAdapter;
use Votepit\SmtpConfig;

/** The SMTP DSN announces the sender domain in EHLO instead of Symfony's "[127.0.0.1]" CLI fallback. */
final class SymfonyMailerAdapterDsnTest extends TestCase
{
    public function test_ehlo_name_is_the_from_domain(): void
    {
        $dsn = Dsn::fromString(SymfonyMailerAdapter::transportDsn(SmtpConfig::fromArray([
            'host'       => 'mail.example.net',
            'port'       => 587,
            'from_email' => 'noreply@app.example.com',
        ])));

        self::assertSame('app.example.com', $dsn->getOption('local_domain'));
        self::assertSame('mail.example.net', $dsn->getHost());
    }

    public function test_verify_peer_option_is_kept_next_to_the_ehlo_name(): void
    {
        $dsn = Dsn::fromString(SymfonyMailerAdapter::transportDsn(SmtpConfig::fromArray([
            'host'        => 'mail.example.net',
            'port'        => 465,
            'encryption'  => 'ssl',
            'verify_peer' => false,
            'from_email'  => 'noreply@app.example.com',
        ])));

        self::assertSame('smtps', $dsn->getScheme());
        self::assertSame('0', $dsn->getOption('verify_peer'));
        self::assertSame('app.example.com', $dsn->getOption('local_domain'));
    }
}
