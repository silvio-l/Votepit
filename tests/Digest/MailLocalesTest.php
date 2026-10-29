<?php

declare(strict_types=1);

namespace Votepit\Tests\Digest;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Votepit\Digest\NewIdeasDigestMail;
use Votepit\Digest\ParticipantDigestMail;
use Votepit\Mail\MailLanguage;

/**
 * Every participant-facing digest mail is available in every supported
 * language: the same keys and placeholders per locale, one render per locale,
 * and an unknown language falls back to English.
 */
final class MailLocalesTest extends TestCase
{
    /**
     * @param class-string $class
     * @return array<string, array<string, string>>
     */
    private function texts(string $class): array
    {
        $texts = (new \ReflectionClassConstant($class, 'TEXTS'))->getValue();
        self::assertIsArray($texts);

        return $texts;
    }

    /** @return list<string> */
    private function placeholders(string $text): array
    {
        preg_match_all('/\{[a-z_]+\}/', $text, $m);
        $found = array_values(array_unique($m[0]));
        sort($found);

        return $found;
    }

    /** @return iterable<string, array{string}> */
    public static function languages(): iterable
    {
        foreach (MailLanguage::SUPPORTED as $language) {
            yield $language => [$language];
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function digestClasses(): iterable
    {
        yield 'participant digest' => [ParticipantDigestMail::class, 'participant'];
        yield 'new ideas digest' => [NewIdeasDigestMail::class, 'new_ideas'];
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('digestClasses')]
    public function test_every_locale_has_every_key_with_the_same_placeholders(string $class): void
    {
        $texts = $this->texts($class);
        $english = $texts[MailLanguage::DEFAULT];
        $keys = array_keys($english);
        sort($keys);

        foreach (MailLanguage::SUPPORTED as $language) {
            self::assertArrayHasKey($language, $texts, "{$class} is missing locale {$language}");
            $locale = $texts[$language];

            $localeKeys = array_keys($locale);
            sort($localeKeys);
            self::assertSame($keys, $localeKeys, "{$class} {$language} key set differs from en");

            foreach ($english as $key => $englishText) {
                self::assertSame(
                    $this->placeholders($englishText),
                    $this->placeholders($locale[$key]),
                    "{$class} {$language}.{$key} placeholders differ from en",
                );
            }
        }
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('digestClasses')]
    public function test_no_locale_uses_forbidden_copy(string $class): void
    {
        foreach ($this->texts($class) as $language => $locale) {
            foreach ($locale as $key => $text) {
                foreach (['consensus', 'gdpr-compliant', 'eu-only'] as $forbidden) {
                    self::assertStringNotContainsString($forbidden, strtolower($text), "{$class} {$language}.{$key}");
                }
            }
        }
    }

    #[DataProvider('languages')]
    public function test_participant_digest_renders_one_mail_per_locale(string $language): void
    {
        $mail = ParticipantDigestMail::render(
            $language,
            [[
                'event_kind' => ParticipantDigestMail::EVENT_STATUS,
                'title'      => 'Dark mode',
                'status'     => 'planned',
                'reason'     => 'Good idea',
                'excerpt'    => null,
                'link'       => 'https://app.test/acme/board/1',
            ]],
            'https://app.test/prefs',
            'https://app.test/unsub',
            'https://app.test',
            false,
            null,
        );

        self::assertNotSame('', $mail['subject']);
        self::assertStringContainsString('Dark mode', $mail['subject']);
        self::assertStringNotContainsString('{', $mail['subject']);
        self::assertStringNotContainsString('{', $mail['text']);
        self::assertStringContainsString('Dark mode', $mail['text']);
    }

    #[DataProvider('languages')]
    public function test_participant_digest_many_items_render_per_locale(string $language): void
    {
        $item = [
            'event_kind' => ParticipantDigestMail::EVENT_COMMENT,
            'title'      => 'Dark mode',
            'status'     => null,
            'reason'     => null,
            'excerpt'    => null,
            'link'       => 'https://app.test/acme/board/1',
        ];
        $mail = ParticipantDigestMail::render($language, [$item, $item], 'https://app.test/prefs', 'https://app.test/unsub', 'https://app.test', true, null);

        self::assertStringContainsString('2', $mail['subject']);
        self::assertStringNotContainsString('{', $mail['subject']);
        self::assertStringNotContainsString('{', $mail['text']);
    }

    public function test_participant_digest_links_items_and_settings_instead_of_bare_urls(): void
    {
        $item = [
            'event_kind' => ParticipantDigestMail::EVENT_STATUS,
            'title'      => 'Dark mode',
            'status'     => 'Geplant', // export-ok: comment-language
            'reason'     => 'Kommt im Mai', // export-ok: comment-language
            'excerpt'    => null,
            'link'       => 'https://app.test/acme/board/1',
        ];
        $mail = ParticipantDigestMail::render('de', [$item, $item], 'https://app.test/prefs', 'https://app.test/unsub', 'https://app.test', false, null);

        // Each item is one linked block; no paragraph consists of a bare URL.
        self::assertSame(2, substr_count($mail['html'], '<a href="https://app.test/acme/board/1"'));
        self::assertStringNotContainsString('>https://app.test/acme/board/1</p>', $mail['html']);
        self::assertStringContainsString('<a href="https://app.test/prefs"', $mail['html']);
        self::assertStringNotContainsString('>https://app.test/prefs', $mail['html']);
        // The plaintext part still carries every URL.
        self::assertStringContainsString("Begründung: Kommt im Mai\nhttps://app.test/acme/board/1", $mail['text']); // export-ok: comment-language
        self::assertStringContainsString('https://app.test/prefs', $mail['text']);
    }

    #[DataProvider('languages')]
    public function test_new_ideas_digest_renders_one_mail_per_locale(string $language): void
    {
        $mail = NewIdeasDigestMail::render(
            $language,
            [['board_name' => 'Dark mode board', 'board_url' => 'https://app.test/acme/board', 'ideas' => [['id' => 1, 'title' => 'Night theme']]]],
            'https://app.test',
            'https://app.test/prefs',
            'https://app.test/unsub',
            null,
        );

        self::assertStringContainsString('Dark mode board', $mail['subject']);
        self::assertStringNotContainsString('{', $mail['subject']);
        self::assertStringNotContainsString('{', $mail['text']);
        self::assertStringContainsString('Night theme', $mail['text']);
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('digestClasses')]
    public function test_an_unknown_language_falls_back_to_english(string $class, string $kind): void
    {
        if ($kind === 'participant') {
            $render = static fn (string $lang): array => ParticipantDigestMail::render(
                $lang,
                [['event_kind' => ParticipantDigestMail::EVENT_COMMENT, 'title' => 'T', 'status' => null, 'reason' => null, 'excerpt' => null, 'link' => 'https://app.test/x']],
                'https://app.test/prefs',
                'https://app.test/unsub',
                'https://app.test',
                false,
                null,
            );
        } else {
            $render = static fn (string $lang): array => NewIdeasDigestMail::render(
                $lang,
                [['board_name' => 'B', 'board_url' => 'https://app.test/b', 'ideas' => [['id' => 1, 'title' => 'T']]]],
                'https://app.test',
                'https://app.test/prefs',
                'https://app.test/unsub',
                null,
            );
        }

        $english = $render('en');
        $unknown = $render('pt');

        self::assertSame($english['subject'], $unknown['subject']);
        self::assertSame($english['text'], $unknown['text']);
    }

    public function test_an_unknown_language_resolves_to_english_in_the_allow_list(): void
    {
        self::assertSame('en', MailLanguage::resolve('pt'));
        self::assertSame('en', MailLanguage::resolve(null));
        self::assertSame('en', MailLanguage::resolve('fr-FR'));
        self::assertSame('fr', MailLanguage::resolve('fr'));
    }

    /**
     * The copyable-link line under the button is translated in every locale
     * (only the English text is the MailTemplate default).
     */
    /**
     * @param class-string $class
     */
    #[DataProvider('digestClasses')]
    public function test_button_fallback_is_translated_in_every_non_english_locale(string $class): void
    {
        $texts = $this->texts($class);
        $english = $texts[MailLanguage::DEFAULT]['button_fallback'];

        foreach (MailLanguage::SUPPORTED as $language) {
            if ($language === MailLanguage::DEFAULT) {
                continue;
            }
            self::assertNotSame($english, $texts[$language]['button_fallback'], "{$class} {$language} fallback is not translated");
        }
    }
}
