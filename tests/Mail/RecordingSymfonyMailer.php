<?php

declare(strict_types=1);

namespace Votepit\Tests\Mail;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\RawMessage;

/** Records every message handed to the Symfony transport — SymfonyMailerAdapterHeadersTest. */
final class RecordingSymfonyMailer implements MailerInterface
{
    /** @var list<RawMessage> */
    public array $sent = [];

    public function send(RawMessage $message, ?Envelope $envelope = null): void
    {
        $this->sent[] = $message;
    }
}
