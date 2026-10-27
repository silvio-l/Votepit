<?php

declare(strict_types=1);

namespace Votepit\Translation;

/**
 * Thrown by a TranslationHttpClient implementation on a transport-level
 * failure (DNS/connect/timeout/TLS) — deliberately NOT thrown for a
 * completed HTTP response with a non-2xx status (the caller inspects
 * `status`/`body` for that). GoogleTranslateProvider catches this and
 * every other failure mode and turns it into TranslationUnavailableException
 * — this type never crosses the TranslationProvider interface boundary.
 */
final class TranslationHttpException extends \RuntimeException {}
