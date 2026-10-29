<?php

declare(strict_types=1);

namespace Votepit\Tests\Mail;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Mime\Email;
use Votepit\Mail\SymfonyMailerAdapter;
use Votepit\SmtpConfig;

/**
 * SymfonyMailerAdapter::send() only accepts the RFC 8058 one-click headers
 * from callers. Any other header name, or a value with CR/LF (header
 * injection), is refused before anything reaches the transport.
 */
final class SymfonyMailerAdapterHeadersTest extends TestCase
{
    private function adapter(RecordingSymfonyMailer $mailer): SymfonyMailerAdapter
    {
        $smtp = SmtpConfig::fromArray(['host' => 'smtp.example.com', 'from_email' => 'noreply@example.com']);

        return new SymfonyMailerAdapter($smtp, $mailer);
    }

    public function test_the_one_click_unsubscribe_headers_are_passed_through(): void
    {
        $mailer  = new RecordingSymfonyMailer();
        $headers = [
            'List-Unsubscribe'      => '<https://app.example.com/unsub?token=abc>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ];

        $this->adapter($mailer)->send('to@example.com', 'Subject', 'Body', null, null, null, $headers);

        self::assertCount(1, $mailer->sent);
        $message = $mailer->sent[0];
        self::assertInstanceOf(Email::class, $message);
        self::assertSame('<https://app.example.com/unsub?token=abc>', $message->getHeaders()->get('List-Unsubscribe')?->getBodyAsString());
        self::assertSame('List-Unsubscribe=One-Click', $message->getHeaders()->get('List-Unsubscribe-Post')?->getBodyAsString());
    }

    public function test_a_header_outside_the_allow_list_is_refused_and_nothing_is_sent(): void
    {
        $mailer = new RecordingSymfonyMailer();

        $this->expectException(\InvalidArgumentException::class);
        try {
            $this->adapter($mailer)->send('to@example.com', 'Subject', 'Body', null, null, null, ['Bcc' => 'hidden@example.com']);
        } finally {
            self::assertCount(0, $mailer->sent);
        }
    }

    public function test_a_crlf_value_in_an_allowed_header_is_refused_and_nothing_is_sent(): void
    {
        $mailer = new RecordingSymfonyMailer();

        $this->expectException(\InvalidArgumentException::class);
        try {
            $this->adapter($mailer)->send(
                'to@example.com',
                'Subject',
                'Body',
                null,
                null,
                null,
                ['List-Unsubscribe' => "<https://app.example.com/x>\r\nBcc: hidden@example.com"],
            );
        } finally {
            self::assertCount(0, $mailer->sent);
        }
    }
}
