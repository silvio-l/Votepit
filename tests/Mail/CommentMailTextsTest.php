<?php

declare(strict_types=1);

namespace Votepit\Tests\Mail;

use PHPUnit\Framework\TestCase;
use Votepit\Mail\CommentMailTexts;
use Votepit\Mail\MailLanguage;

/**
 * The instant comment / thread-reply mail copy: same keys and placeholders in
 * every supported language, one render per locale, English fallback for an
 * unknown language.
 */
final class CommentMailTextsTest extends TestCase
{
    /** @return list<string> */
    private function placeholders(string $text): array
    {
        preg_match_all('/\{[a-z_]+\}/', $text, $m);
        $found = array_values(array_unique($m[0]));
        sort($found);

        return $found;
    }

    /** @return array<string, array<string, string>> */
    private function texts(): array
    {
        /** @var array<string, array<string, string>> $texts */
        $texts = (new \ReflectionClassConstant(CommentMailTexts::class, 'TEXTS'))->getValue();

        return $texts;
    }

    public function test_every_locale_has_every_key_with_the_same_placeholders(): void
    {
        $texts = $this->texts();
        $english = $texts[MailLanguage::DEFAULT];
        $keys = array_keys($english);
        sort($keys);

        foreach (MailLanguage::SUPPORTED as $language) {
            self::assertArrayHasKey($language, $texts);
            $localeKeys = array_keys($texts[$language]);
            sort($localeKeys);
            self::assertSame($keys, $localeKeys, "key set for {$language}");

            foreach ($english as $key => $englishText) {
                self::assertSame(
                    $this->placeholders($englishText),
                    $this->placeholders($texts[$language][$key]),
                    "{$language}.{$key} placeholders",
                );
            }
        }
    }

    public function test_every_locale_renders_both_notices_with_the_idea_title(): void
    {
        foreach (MailLanguage::SUPPORTED as $language) {
            $comment = CommentMailTexts::ideaComment($language, 'Dark mode');
            $reply = CommentMailTexts::threadReply($language, 'Dark mode');

            self::assertNotSame('', $comment['title']);
            self::assertNotSame('', $reply['title']);
            self::assertStringContainsString('Dark mode', $comment['body']);
            self::assertStringContainsString('Dark mode', $reply['body']);
            self::assertStringNotContainsString('{title}', $comment['body']);
            self::assertStringNotContainsString('{title}', $reply['body']);
            self::assertNotSame('', CommentMailTexts::cta($language));
            self::assertNotSame('', CommentMailTexts::unsubscribeLabel($language));
            self::assertNotSame('', CommentMailTexts::buttonFallback($language));
        }
    }

    public function test_english_wording_is_unchanged(): void
    {
        self::assertSame(
            ['title' => 'New comment on your idea', 'body' => 'Someone commented on "Dark mode".'],
            CommentMailTexts::ideaComment('en', 'Dark mode'),
        );
        self::assertSame(
            ['title' => 'New reply in a thread you commented on', 'body' => 'There\'s a new comment on "Dark mode".'],
            CommentMailTexts::threadReply('en', 'Dark mode'),
        );
    }

    public function test_an_unknown_language_falls_back_to_english(): void
    {
        self::assertSame(CommentMailTexts::ideaComment('en', 'X'), CommentMailTexts::ideaComment('pt', 'X'));
        self::assertSame(CommentMailTexts::threadReply('en', 'X'), CommentMailTexts::threadReply('pt', 'X'));
        self::assertSame(CommentMailTexts::cta('en'), CommentMailTexts::cta('pt'));
        self::assertSame(CommentMailTexts::unsubscribeLabel('en'), CommentMailTexts::unsubscribeLabel(''));
    }

    public function test_german_button_fallback_is_translated(): void
    {
        self::assertSame(
            'Falls der Button nicht funktioniert, kopiere diesen Link in deinen Browser:',  // export-ok: comment-language
            CommentMailTexts::buttonFallback('de'),
        );
        self::assertSame(
            'If the button does not work, copy this link into your browser:',
            CommentMailTexts::buttonFallback('en'),
        );
    }
}
