<?php

declare(strict_types=1);

namespace Votepit\Update;

/**
 * The previous code could not be restored automatically. The backup it names
 * is then the only complete copy of the old installation, so the updater must
 * keep the workspace instead of cleaning it up.
 */
final class RollbackFailedException extends UpdateException {}
