<?php

declare(strict_types=1);

namespace Votepit;

/**
 * Thrown for invalid/missing configuration.
 * The front controller catches it and returns a lean 500 page without details.
 */
final class ConfigException extends \RuntimeException {}
