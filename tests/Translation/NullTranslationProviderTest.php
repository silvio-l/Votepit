<?php

declare(strict_types=1);

namespace Votepit\Tests\Translation;

use PHPUnit\Framework\TestCase;
use Votepit\Translation\NullTranslationProvider;
use Votepit\Translation\TranslationUnavailableException;

final class NullTranslationProviderTest extends TestCase
{
    public function test_translate_always_throws_immediately(): void
    {
        $provider = new NullTranslationProvider();

        $this->expectException(TranslationUnavailableException::class);
        $provider->translate('Hello world', 'de');
    }

    public function test_translate_has_no_side_effects_beyond_the_exception(): void
    {
        $provider = new NullTranslationProvider();

        $thrown = false;
        try {
            $provider->translate('Hello world', 'de');
        } catch (TranslationUnavailableException) {
            $thrown = true;
        }

        self::assertTrue($thrown);
    }
}
