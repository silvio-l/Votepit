<?php

declare(strict_types=1);

namespace Votepit\Tests\Domain;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Votepit\Domain\ContentModerationService;

/**
 * Unit-Tests für ContentModerationService::check (Issue 08 — lokaler Wortfilter).
 *
 * Testet ausschließlich über die public API check() + withAdditionalWords().
 * Tabellengetrieben mit DataProvider, analog zu TitleNormalizerTest.
 *
 * Getestete Eigenschaften:
 *   - AC1/AC2 — reiner Domain-Service, Basislisten aus resources/moderation/ injizierbar
 *   - AC3 — Treffer auf DE + EN Einzelwörter und Mehrwort-Phrasen
 *   - AC4 — Ganzwort-Matching: harmlose Wörter mit Listenwort als Teilstring triggern NICHT
 *   - AC5 — Leetspeak-Erkennung
 *   - AC6 — sauberer Text → clean:true / Treffer → clean:false + hits maschinenlesbar
 *   - AC7 — immutable withAdditionalWords()
 *   - AC8 — Tabellengetriebene Tests; composer qa grün
 */
final class ContentModerationServiceTest extends TestCase
{
    /** Points to the real vendored lists */
    private ContentModerationService $sut;

    /** Fixture directory with minimal test lists (injected for isolation) */
    private string $fixtureDir;

    protected function setUp(): void
    {
        $this->sut = new ContentModerationService();
        $this->fixtureDir = __DIR__ . '/fixtures/moderation';
    }

    // -------------------------------------------------------------------------
    // AC1 + AC2 — Instanziierung + Basislisten-Pfad injizierbar
    // -------------------------------------------------------------------------

    public function test_instantiates_with_default_resource_dir(): void
    {
        $sut = new ContentModerationService();
        $result = $sut->check('hello world');

        // Verify the result shape: clean flag is true for innocent input, hits is empty list
        self::assertTrue($result['clean']);
        self::assertSame([], $result['hits']);
    }

    public function test_injected_fixture_dir_is_used(): void
    {
        $this->createFixtures(['badword']);
        $sut = new ContentModerationService($this->fixtureDir);

        self::assertFalse($sut->check('this contains badword here')['clean']);
    }

    // -------------------------------------------------------------------------
    // AC3 — Treffer: DE + EN Einzelwörter und Mehrwort-Phrasen
    // -------------------------------------------------------------------------

    /**
     * @return array<string, array{string}>
     */
    public static function clearHitCases(): array
    {
        return [
            // English single words (from en.txt)
            'en: anal'              => ['anal'],
            'en: ass'               => ['ass'],
            'en: anus'              => ['anus'],
            // German single words (from de.txt)
            'de: arschloch'         => ['arschloch'],
            'de: fick'              => ['fick'],
            'de: hurensohn'         => ['hurensohn'],
            // Multi-word phrase (from en.txt)
            'en: 2 girls 1 cup'     => ['2 girls 1 cup'],
            'en: alabama hot pocket' => ['alabama hot pocket'],
        ];
    }

    #[DataProvider('clearHitCases')]
    public function test_clear_hit_is_blocked(string $input): void
    {
        $result = $this->sut->check($input);

        self::assertFalse($result['clean'], "Expected '{$input}' to be blocked");
        self::assertNotEmpty($result['hits']);
    }

    public function test_blocked_result_contains_matched_phrase(): void
    {
        $result = $this->sut->check('arschloch');

        self::assertFalse($result['clean']);
        self::assertContains('arschloch', $result['hits']);
    }

    public function test_multiword_phrase_matched_in_sentence(): void
    {
        $result = $this->sut->check('The feature request is about alabama hot pocket handling');

        self::assertFalse($result['clean']);
        self::assertContains('alabama hot pocket', $result['hits']);
    }

    // -------------------------------------------------------------------------
    // AC4 — Ganzwort-Matching: Scunthorpe-Regression
    // -------------------------------------------------------------------------

    /**
     * Words that contain a short blocklisted substring but must NOT trigger.
     *
     * @return array<string, array{string}>
     */
    public static function scunthorpeSafeCases(): array
    {
        return [
            // "ass" is on the list; these must not trigger
            'massage'           => ['massage'],
            'assassin'          => ['assassin'],
            'ambassador'        => ['ambassador'],
            'glassware'         => ['glassware'],
            // "anal" is on the list; these must not trigger
            'analogy'           => ['analogy'],
            'canal'             => ['canal'],
            'banal'             => ['banal'],
            // German: "fick" is on the list; "schiffchen" must not trigger
            'schiffchen'        => ['schiffchen'],
            // "anus" is on the list; "anus" as standalone is a hit, but
            // "Anus-Prüfung" without the word alone is fine — test a clear non-match
            'manuskript'        => ['manuskript'],
        ];
    }

    #[DataProvider('scunthorpeSafeCases')]
    public function test_scunthorpe_word_does_not_trigger(string $input): void
    {
        $result = $this->sut->check($input);

        self::assertTrue(
            $result['clean'],
            "Expected '{$input}' to be clean (Scunthorpe guard), but got hits: "
            . implode(', ', $result['hits'])
        );
    }

    // -------------------------------------------------------------------------
    // AC5 — Leetspeak-Erkennung
    // -------------------------------------------------------------------------

    /**
     * @return array<string, array{string, string}>
     */
    public static function leetspeakCases(): array
    {
        return [
            '4rschl0ch → arschloch' => ['4rschl0ch', 'arschloch'],
            'f1ck → fick'           => ['f1ck', 'fick'],
            '@rschloch → arschloch' => ['@rschloch', 'arschloch'],
            '4$$hole → asshole'     => ['4$$hole', 'asshole'],
            '4n4l → anal'           => ['4n4l', 'anal'],
        ];
    }

    #[DataProvider('leetspeakCases')]
    public function test_leetspeak_variant_is_blocked(string $input, string $expectedHit): void
    {
        $result = $this->sut->check($input);

        self::assertFalse($result['clean'], "Expected leetspeak '{$input}' to be blocked");
        self::assertContains($expectedHit, $result['hits']);
    }

    // -------------------------------------------------------------------------
    // AC6 — Sauberer Text → clean:true, hits:[]
    // -------------------------------------------------------------------------

    /**
     * @return array<string, array{string}>
     */
    public static function cleanInputCases(): array
    {
        return [
            'empty string'          => [''],
            'hello world'           => ['hello world'],
            'feature request'       => ['Please add dark mode support'],
            'German clean sentence' => ['Ich möchte eine bessere Suchfunktion'],
            'Kassenwart'            => ['Kassenwart'],   // contains "ss" and "wart"
            'Massachusetts'         => ['Massachusetts'],
            'Sachsen'               => ['Sachsen'],
        ];
    }

    #[DataProvider('cleanInputCases')]
    public function test_clean_input_returns_clean_true(string $input): void
    {
        $result = $this->sut->check($input);

        self::assertTrue($result['clean'], "Expected '{$input}' to be clean, hits: " . implode(', ', $result['hits']));
        self::assertSame([], $result['hits']);
    }

    // -------------------------------------------------------------------------
    // AC6 cont. — Multiple texts combined (title + body)
    // -------------------------------------------------------------------------

    public function test_clean_in_both_title_and_body(): void
    {
        $result = $this->sut->check('Feature request', 'Please make the UI faster');

        self::assertTrue($result['clean']);
    }

    public function test_hit_in_body_text_is_caught(): void
    {
        $result = $this->sut->check('My feature request', 'This is total arschloch behaviour');

        self::assertFalse($result['clean']);
        self::assertContains('arschloch', $result['hits']);
    }

    public function test_hit_in_title_is_caught(): void
    {
        $result = $this->sut->check('arschloch feature', 'Some body text here');

        self::assertFalse($result['clean']);
        self::assertContains('arschloch', $result['hits']);
    }

    public function test_result_hits_are_unique(): void
    {
        // Same word appearing multiple times → only one entry in hits
        $result = $this->sut->check('arschloch is arschloch');

        self::assertCount(1, $result['hits']);
    }

    // -------------------------------------------------------------------------
    // AC7 — Immutable withAdditionalWords()
    // -------------------------------------------------------------------------

    public function test_additional_words_block_new_phrase(): void
    {
        $extended = $this->sut->withAdditionalWords(['totallyinnocent']);

        $result = $extended->check('this is totallyinnocent content');

        self::assertFalse($result['clean']);
        self::assertContains('totallyinnocent', $result['hits']);
    }

    public function test_base_instance_unchanged_after_with_additional_words(): void
    {
        $extended = $this->sut->withAdditionalWords(['totallyinnocent']);

        // Base instance must not be affected
        $baseResult = $this->sut->check('this is totallyinnocent content');
        self::assertTrue($baseResult['clean'], 'Base instance must remain unmodified');

        // Extended instance still blocks it
        $extResult = $extended->check('this is totallyinnocent content');
        self::assertFalse($extResult['clean']);
    }

    public function test_with_additional_words_returns_new_instance(): void
    {
        $extended = $this->sut->withAdditionalWords(['something']);

        self::assertNotSame($this->sut, $extended);
    }

    public function test_additional_words_are_case_insensitive(): void
    {
        $extended = $this->sut->withAdditionalWords(['BadPhrase']);

        $result = $extended->check('There is a BADPHRASE in here');

        self::assertFalse($result['clean']);
    }

    // -------------------------------------------------------------------------
    // Internal helpers for fixture-based tests
    // -------------------------------------------------------------------------

    /**
     * Create minimal fixture files in a temp-like directory under tests/
     *
     * @param list<string> $words
     */
    private function createFixtures(array $words): void
    {
        if (!is_dir($this->fixtureDir)) {
            mkdir($this->fixtureDir, 0o755, true);
        }
        file_put_contents($this->fixtureDir . '/en.txt', implode("\n", $words) . "\n");
        // Empty de.txt so the loader doesn't skip it
        file_put_contents($this->fixtureDir . '/de.txt', '');
    }
}
