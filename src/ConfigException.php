<?php

declare(strict_types=1);

namespace Votepit;

/**
 * Wird bei ungültiger/fehlender Konfiguration geworfen.
 * Der Front-Controller fängt sie und liefert eine schlanke 500-Seite ohne Details.
 */
final class ConfigException extends \RuntimeException {}
