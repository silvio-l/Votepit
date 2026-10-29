<?php

declare(strict_types=1);

namespace Votepit\Persistence;

/** Thrown by UserRepository::setUsername() when the name is already taken (case-insensitively). */
final class UsernameTakenException extends \RuntimeException {}
