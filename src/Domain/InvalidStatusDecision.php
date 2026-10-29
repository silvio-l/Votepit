<?php

declare(strict_types=1);

namespace Votepit\Domain;

/**
 * Raised by StatusDecision::fromInput() for a malformed or over-long decision
 * field. Carries the request field name only, never the submitted value.
 */
final class InvalidStatusDecision extends \DomainException
{
    public function __construct(public readonly string $field)
    {
        parent::__construct('Invalid status decision field: ' . $field);
    }
}
