<?php

declare(strict_types=1);

namespace Votepit\Translation;

/**
 * Thrown by a TranslationProvider on ANY failure — auth error, network
 * error, non-2xx response, quota exceeded, malformed/empty response, or
 * (NullTranslationProvider) translation simply not configured. Callers
 * (the translate actions) catch this and respond 503 with a generic
 * `{"error":{"key":"translation_unavailable"}}` body — never a 500, and
 * the original idea/comment content stays fully visible via the normal
 * board/idea endpoints regardless of this exception.
 */
final class TranslationUnavailableException extends TranslationException {}
