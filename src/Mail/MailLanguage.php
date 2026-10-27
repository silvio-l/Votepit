<?php

declare(strict_types=1);

namespace Votepit\Mail;

/**
 * The closed set of languages participant-facing mail is rendered in. It
 * mirrors the SPA's UI languages (app/src/lib/i18n/context.tsx LANGUAGES).
 * Anything else, including NULL, falls back to English.
 */
final class MailLanguage
{
    public const DEFAULT = 'en';

    /** @var list<string> */
    public const SUPPORTED = ['de', 'en', 'es', 'fr', 'it', 'nl'];

    public static function resolve(mixed $language): string
    {
        return is_string($language) && in_array($language, self::SUPPORTED, true) ? $language : self::DEFAULT;
    }
}
