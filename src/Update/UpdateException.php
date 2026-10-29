<?php

declare(strict_types=1);

namespace Votepit\Update;

/**
 * A refused or failed self-update step. The message is operator-facing and
 * says what to do; nothing in it is secret.
 */
class UpdateException extends \RuntimeException {}
