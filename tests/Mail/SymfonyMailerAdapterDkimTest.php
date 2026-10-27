<?php

declare(strict_types=1);

namespace Votepit\Tests\Mail;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\RawMessage;
use Votepit\Mail\SymfonyMailerAdapter;
use Votepit\SmtpConfig;

/**
 * SymfonyMailerAdapter — optional DKIM signing (RFC 6376).
 *
 * AC1: without dkim_private_key/dkim_selector configured, mail sends
 *      unsigned exactly as before (backward compatible default).
 * AC2: with both configured, the sent message carries a DKIM-Signature
 *      header whose d= matches the From-address's domain (DMARC alignment)
 *      and whose s= matches the configured selector.
 */
final class SymfonyMailerAdapterDkimTest extends TestCase
{
    /** @var MailerInterface&object{sent: RawMessage|null} */
    private MailerInterface $symfonyMailer;

    protected function setUp(): void
    {
        if (!extension_loaded('openssl')) {
            self::markTestSkipped('openssl extension not available');
        }

        $this->symfonyMailer = new class () implements MailerInterface {
            public ?RawMessage $sent = null;

            public function send(RawMessage $message, ?Envelope $envelope = null): void
            {
                $this->sent = $message;
            }
        };
    }

    private function generatePrivateKeyPem(): string
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($key);
        openssl_pkey_export($key, $pem);

        return $pem;
    }

    public function test_sends_unsigned_when_dkim_not_configured(): void
    {
        $adapter = new SymfonyMailerAdapter(
            SmtpConfig::fromArray(['from_email' => 'noreply@votepit.example']),
            $this->symfonyMailer,
        );

        $adapter->send('to@votepit.example', 'Subject', 'Body');

        self::assertInstanceOf(Email::class, $this->symfonyMailer->sent);
        self::assertFalse($this->symfonyMailer->sent->getHeaders()->has('DKIM-Signature'));
    }

    public function test_signs_with_dkim_when_configured(): void
    {
        $adapter = new SymfonyMailerAdapter(
            SmtpConfig::fromArray([
                'from_email' => 'noreply@app.votepit.example',
                'dkim_private_key' => $this->generatePrivateKeyPem(),
                'dkim_selector' => 'votepit2026',
            ]),
            $this->symfonyMailer,
        );

        $adapter->send('to@votepit.example', 'Subject', 'Body');

        $sent = $this->symfonyMailer->sent;
        self::assertInstanceOf(Message::class, $sent);
        self::assertTrue($sent->getHeaders()->has('DKIM-Signature'));
        $header = $sent->getHeaders()->get('DKIM-Signature');
        self::assertNotNull($header);
        $signature = $header->getBodyAsString();
        self::assertStringContainsString('d=app.votepit.example', $signature);
        self::assertStringContainsString('s=votepit2026', $signature);
    }
}
