<?php

declare(strict_types=1);

namespace Votepit\Dev;

/**
 * Thrown when demo-data seeding is requested outside a local development
 * installation. Seeding is refused rather than attempted.
 */
final class DevSeedRefusedException extends \RuntimeException {}
