<?php

declare(strict_types=1);

namespace Votepit\Tests\Mail;

use PHPUnit\Framework\TestCase;
use Votepit\Mail\MailBlock;
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
        self::assertStringContainsString('background-color: #167665;', $mail['html']);
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

    // The copyable-link line under the button is localisable: English stays the
    // default, a translation replaces it in the HTML part only.
    public function test_button_fallback_line_defaults_to_english_and_takes_a_translation(): void
    {
        $link = 'https://votepit.example/login/verify?token=abc123';
        $english = MailTemplate::render('Heading', ['Hallo,'], $link, 'Anmelden');
        $german = MailTemplate::render(
            'Heading',
            ['Hallo,'],
            $link,
            'Anmelden',
            buttonFallback: 'Falls der Button nicht funktioniert, kopiere diesen Link in deinen Browser:',  // export-ok: comment-language
        );

        self::assertStringContainsString(MailTemplate::DEFAULT_BUTTON_FALLBACK . '<br>', $english['html']);
        self::assertStringContainsString('Falls der Button nicht funktioniert, kopiere diesen Link in deinen Browser:<br>', $german['html']);  // export-ok: comment-language
        self::assertStringNotContainsString('If the button does not work', $german['html']);
        self::assertSame($english['text'], $german['text'], 'the plaintext part carries no fallback line');
    }

    public function test_button_fallback_line_is_escaped(): void
    {
        $mail = MailTemplate::render('Heading', ['Hi'], 'https://votepit.example/x', 'Open', buttonFallback: 'Link <b>here</b> & go:');

        self::assertStringContainsString('Link &lt;b&gt;here&lt;/b&gt; &amp; go:<br>', $mail['html']);
        self::assertStringNotContainsString('<b>here</b>', $mail['html']);
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
        self::assertStringNotContainsString('#167665', $mail['html']);
        self::assertStringNotContainsString('href=', $mail['html']);
        self::assertStringNotContainsString('border-top', $mail['html']);
        self::assertStringContainsString('SMTP test successful</h1>', $mail['html']);
    }

    // Footer link (weekly digest unsubscribe): a clickable, escaped muted
    // link in the HTML footer and a "label: url" line in the plaintext part.
    public function test_footer_link_renders_in_both_parts_and_is_escaped(): void
    {
        $url  = 'https://votepit.example/digest/unsubscribe?token=a.b&x=<y>';
        $mail = MailTemplate::render(
            'Weekly digest',
            ['Hello,'],
            footerLines: ['You get this because you own an account.'],
            footerLink: ['label' => 'Unsubscribe', 'url' => $url],
        );

        self::assertStringContainsString("You get this because you own an account.\nUnsubscribe: {$url}\n", $mail['text']);

        $escaped = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        self::assertStringContainsString('href="' . $escaped . '"', $mail['html']);
        self::assertStringContainsString('>Unsubscribe</a>', $mail['html']);
        self::assertStringNotContainsString('<y>', $mail['html']);
    }

    public function test_html_is_a_light_only_document_with_a_preheader(): void
    {
        $mail = MailTemplate::render('Your login link', ['Hello,', 'here is your login link for the demo board:'], 'https://example.com/x', 'Log in');

        self::assertStringStartsWith('<!DOCTYPE html><html><head>', $mail['html']);
        self::assertStringEndsWith('</body></html>', $mail['html']);
        // Apple Mail dark mode must not invert the sheet (the dark wordmark vanished).
        self::assertStringContainsString('<meta name="color-scheme" content="light only">', $mail['html']);
        self::assertStringContainsString('color-scheme: light only;', $mail['html']);
        // The preheader skips the bare greeting and is hidden in the body.
        self::assertMatchesRegularExpression('#<div style="display: none;[^"]*">here is your login link for the demo board:</div>#', $mail['html']);
        self::assertStringContainsString('<title>Your login link</title>', $mail['html']);
    }

    public function test_preheader_and_title_are_escaped(): void
    {
        $mail = MailTemplate::render('<b>Title</b>', ['A paragraph with <script>alert(1)</script> inside it']);

        self::assertStringContainsString('<title>&lt;b&gt;Title&lt;/b&gt;</title>', $mail['html']);
        self::assertStringNotContainsString('<script>', $mail['html']);
    }

    public function test_blocks_render_styled_and_escaped_and_group_list_items_in_text(): void
    {
        $mail = MailTemplate::render('Heading', [
            'Intro paragraph that is long enough.',
            MailBlock::subheading('Board <b>One</b>'),
            MailBlock::meta('3 ideas & 12 votes'),
            MailBlock::item('First "idea"'),
            MailBlock::item('Second idea'),
            'Closing paragraph.',
        ]);

        self::assertStringContainsString("Board <b>One</b>\n\n3 ideas & 12 votes\n\n- First \"idea\"\n- Second idea\n\nClosing paragraph.", $mail['text']);
        self::assertStringContainsString('font-weight: 700', $mail['html']);
        self::assertStringContainsString('Board &lt;b&gt;One&lt;/b&gt;', $mail['html']);
        self::assertStringContainsString('3 ideas &amp; 12 votes', $mail['html']);
        self::assertStringContainsString('First &quot;idea&quot;', $mail['html']);
        self::assertStringNotContainsString('<b>One</b>', $mail['html']);
    }

    public function test_cta_slot_stats_idea_rows_header_note_and_footer_links(): void
    {
        $mail = MailTemplate::render('Heading', [
            'Lead paragraph that is long enough.',
            MailBlock::cta(),
            MailBlock::subheading('Board', 'https://app.test/b?x=1&y=2'),
            MailBlock::stats([['value' => '3', 'label' => 'new <ideas>', 'sub' => 'this week']], '3 new ideas'),
            MailBlock::label('Ideas to watch'),
            MailBlock::idea('Idea <i>', 'Most voted · 2 for / 0 against', 'https://app.test/b/idea/1'),
        ], 'https://app.test/go', 'Go', ['Footer line.'], ['label' => 'Unsubscribe', 'url' => 'https://app.test/u'], headerNote: 'Oct 19–25', footerLinks: [['label' => 'Settings', 'url' => 'https://app.test/s']]);

        // Button at the slot, before the first section; no fallback line mid-mail.
        self::assertLessThan(strpos($mail['html'], '>Board</a>'), strpos($mail['html'], '>Go</a>'));
        self::assertStringNotContainsString('If the button does not work', $mail['html']);
        self::assertSame(1, substr_count($mail['html'], '>Go</a>'));
        self::assertStringContainsString('href="https://app.test/b?x=1&amp;y=2"', $mail['html']);
        self::assertStringContainsString('new &lt;ideas&gt;', $mail['html']);
        self::assertStringContainsString('Oct 19–25', $mail['html']);
        self::assertStringContainsString('>Settings</a>', $mail['html']);
        self::assertLessThan(strpos($mail['html'], '>Unsubscribe</a>'), strpos($mail['html'], '>Settings</a>'));

        self::assertSame(
            "Oct 19–25\n\nLead paragraph that is long enough.\n\nGo:\nhttps://app.test/go\n\nBoard\nhttps://app.test/b?x=1&y=2\n\n3 new ideas\n\nIdeas to watch\n\n"
            . "Idea <i>\nMost voted · 2 for / 0 against\nhttps://app.test/b/idea/1\n\nFooter line.\nSettings: https://app.test/s\nUnsubscribe: https://app.test/u\n",
            $mail['text'],
        );
    }
}
