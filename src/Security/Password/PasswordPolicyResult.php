<?php

declare(strict_types=1);

namespace Votepit\Security\Password;

/** Outcome of PasswordPolicy::check() — either ok, or a specific, actionable violation. */
final readonly class PasswordPolicyResult
{
    private function __construct(
        public bool $ok,
        public ?string $errorKey,
        public ?string $errorMessage,
    ) {}

    public static function ok(): self
    {
        return new self(true, null, null);
    }

    public static function violation(string $errorKey, string $errorMessage): self
    {
        return new self(false, $errorKey, $errorMessage);
    }
}
