<?php

declare(strict_types=1);

namespace Votepit\Tests\Mail;

use PHPUnit\Framework\TestCase;
use Votepit\Mail\MailTemplate;

/**
 * MailTemplate — both multipart bodies from one set of content blocks.
 *
 * AC1: the plaintext part reproduces the previous plain-text wording exactly
 *      (paragraphs, link on its own line, single-line footer lines).
 * AC2: the HTML part carries branding (word-and-image mark as a CID inline
 *      image, CTA button in brand teal) and the link both as a button AND as a
 *      copyable fallback; `render()` also returns the logo to embed as an InlineImage.
 * AC3: every interpolated value is HTML-escaped (defense in depth).
 * AC4: without a CTA/footer, neither the button table nor the footer block appears.
 */
final class MailTemplateTest extends TestCase
{
    // AC1 — plaintext identical to the previous login mail wording
    public function test_text_part_matches_legacy_plaintext_wording(): void
    {
        $link = 'https://votepit.example/login/verify?token=abc123';
        $mail = MailTemplate::render(
            'Your login link',
            ['Hello,', 'here is your login link:'],
            $link,
            'Log in now',
            ['The link is valid for 15 minutes.', 'Please do not share it.'],
        );

        self::assertSame(
            "Hello,\n\nhere is your login link:\n\n{$link}\n\nThe link is valid for 15 minutes.\nPlease do not share it.\n",
            $mail['text'],
        );
    }

    // AC2 — HTML carries the word-and-image mark (CID image), CTA button (brand teal) + fallback link
    public function test_html_part_carries_branding_and_cta(): void
    {
        $link = 'https://votepit.example/invite/accept?token=abc&r=%2Fboard';
        $mail = MailTemplate::render(
            'You have been invited',
            ['Hello,', 'you have been invited to a Votepit account:'],
            $link,
            'Accept invitation',
            ['The link is valid for 7 days.'],
        );

        $escapedLink = htmlspecialchars($link, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        // Word-and-image mark as a real CID inline image in the header — no
        // more text-span trick; with alt text and fixed dimensions (Outlook-safe).
        self::assertStringContainsString(
            '<img src="cid:' . MailTemplate::LOGO_CID . '" alt="Votepit" width="148" height="32"',
            $mail['html'],
        );
        self::assertStringNotContainsString('>Vote</span>', $mail['html']);

        // `render()` also returns the logo to embed: the expected CID, path
        // resolved relative to core/, file exists in the repo.
        self::assertSame(MailTemplate::LOGO_CID, $mail['image']->cid);
        self::assertStringEndsWith('/public/assets/brand/votepit-lockup-light.png', $mail['image']->path);
        self::assertFileExists($mail['image']->path);

        // CTA button in brand teal, link escaped as href.
        self::assertStringContainsString('background-color: #1FA890;', $mail['html']);
        self::assertStringContainsString('href="' . $escapedLink . '"', $mail['html']);
        self::assertStringContainsString('>Accept invitation</a>', $mail['html']);

        // Fallback: URL additionally as copyable text.
        self::assertStringContainsString('>' . $escapedLink . '</a>', $mail['html']);

        // Heading + paragraphs + footer line present.
        self::assertStringContainsString('You have been invited</h1>', $mail['html']);
        self::assertStringContainsString('you have been invited to a Votepit account:', $mail['html']);
        self::assertStringContainsString('The link is valid for 7 days.', $mail['html']);

        // The raw (unescaped) link must not appear in the HTML (&r= → &amp;r=).
        self::assertStringNotContainsString('&r=%2Fboard"', $mail['html']);
    }

    // AC3 — HTML escaping of all interpolated values
    public function test_html_escapes_all_interpolated_values(): void
    {
        $mail = MailTemplate::render(
            'Board <script>',
            ['Paragraph with "Quotes" & <Tags>.'],
            'https://votepit.example/x?a=1&b=2',
            'Label & <b>',
            ['Footer <i>'],
        );

        self::assertStringNotContainsString('<script>', $mail['html']);
        self::assertStringNotContainsString('<Tags>', $mail['html']);
        self::assertStringNotContainsString('<b>', $mail['html']);
        self::assertStringNotContainsString('<i>', $mail['html']);
        self::assertStringContainsString('Board &lt;script&gt;', $mail['html']);
        self::assertStringContainsString('Paragraph with &quot;Quotes&quot; &amp; &lt;Tags&gt;.', $mail['html']);
        self::assertStringContainsString('a=1&amp;b=2', $mail['html']);
    }

    // AC4 — without CTA/footer: no button, no footer block, text ends cleanly
    public function test_without_cta_and_footer_renders_minimal_mail(): void
    {
        $mail = MailTemplate::render(
            'SMTP test successful',
            ['This is a Votepit test email.', 'If you can see this message, your SMTP configuration works.'],
        );

        self::assertSame(
            "This is a Votepit test email.\n\nIf you can see this message, your SMTP configuration works.\n",
            $mail['text'],
        );
        self::assertStringNotContainsString('#1FA890', $mail['html']);
        self::assertStringNotContainsString('href=', $mail['html']);
        self::assertStringNotContainsString('border-top', $mail['html']);
        self::assertStringContainsString('SMTP test successful</h1>', $mail['html']);
    }
}
