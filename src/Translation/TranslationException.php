<?php

declare(strict_types=1);

namespace Votepit\Translation;

/**
 * Base exception for the Translation namespace — mirrors
 * Votepit\Security\OAuth\OAuthHttpException's role as a base type, though
 * unlike OAuth this codebase's only caller-visible failure is
 * TranslationUnavailableException (no distinct "completed but non-2xx"
 * case is exposed across this seam — see TranslationProvider's class doc).
 */
class TranslationException extends \RuntimeException {}
