<?php

declare(strict_types=1);

namespace Votepit\Tests\Mail;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;
use Votepit\Mail\MailTemplate;
use Votepit\Mail\SymfonyMailerAdapter;
use Votepit\SmtpConfig;

/**
 * SymfonyMailerAdapter — CID embedding of the header logo.
 *
 * AC1: with an HTML body + InlineImage, the built email attaches the logo as
 *      an inline attachment (name = CID anchor, content = the real PNG file),
 *      and the HTML part references it as `cid:{name}`.
 * AC2: without an HTML body, nothing is embedded (plaintext can't
 *      reference an inline image).
 *
 * Sending via an injected MailerInterface double — no SMTP, no network.
 */
final class SymfonyMailerAdapterEmbedTest extends TestCase
{
    /** @var MailerInterface&object{sent: RawMessage|null} */
    private MailerInterface $symfonyMailer;

    private SymfonyMailerAdapter $adapter;

    protected function setUp(): void
    {
        $this->symfonyMailer = new class () implements MailerInterface {
            public ?RawMessage $sent = null;

            public function send(RawMessage $message, ?Envelope $envelope = null): void
            {
                $this->sent = $message;
            }
        };

        $this->adapter = new SymfonyMailerAdapter(
            SmtpConfig::fromArray(['from_email' => 'noreply@votepit.example']),
            $this->symfonyMailer,
        );
    }

    // AC1 — logo ends up as an inline attachment with the expected CID anchor
    public function test_embeds_inline_image_with_expected_cid(): void
    {
        $mail = MailTemplate::render('Test mail', ['Hello.']);

        $this->adapter->send('to@votepit.example', 'Subject', $mail['text'], $mail['html'], $mail['image']);

        $email = $this->symfonyMailer->sent;
        self::assertInstanceOf(Email::class, $email);
        self::assertStringContainsString('cid:' . MailTemplate::LOGO_CID, (string) $email->getHtmlBody());

        $attachments = $email->getAttachments();
        self::assertCount(1, $attachments);
        self::assertSame(MailTemplate::LOGO_CID, $attachments[0]->getFilename());
        self::assertSame(file_get_contents($mail['image']->path), $attachments[0]->getBody());
    }

    // AC2 — plaintext-only mail embeds nothing
    public function test_plaintext_only_mail_embeds_nothing(): void
    {
        $mail = MailTemplate::render('Test mail', ['Hello.']);

        $this->adapter->send('to@votepit.example', 'Subject', $mail['text'], null, $mail['image']);

        $email = $this->symfonyMailer->sent;
        self::assertInstanceOf(Email::class, $email);
        self::assertSame([], $email->getAttachments());
    }
}
