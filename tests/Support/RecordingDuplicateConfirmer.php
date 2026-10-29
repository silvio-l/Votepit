<?php

declare(strict_types=1);

namespace Votepit\Tests\Support;

use Votepit\Extension\DuplicateConfirmationAvailability;
use Votepit\Extension\DuplicateConfirmer;

/**
 * DuplicateConfirmer test double (competitor-parity issue 11): returns a
 * fixed availability and verdict and records every confirm() call, so core
 * tests can assert what would leave the installation.
 */
final class RecordingDuplicateConfirmer implements DuplicateConfirmer
{
    /** @var list<array{account: int, title: string, candidates: list<array{id: int, title: string}>}> */
    public array $calls = [];

    /** @param list<int>|null $verdict */
    public function __construct(
        public ?array $verdict = [],
        public DuplicateConfirmationAvailability $availability = DuplicateConfirmationAvailability::Available,
    ) {}

    public function availability(int $accountId): DuplicateConfirmationAvailability
    {
        return $this->availability;
    }

    public function confirm(int $accountId, string $title, array $candidates): ?array
    {
        $this->calls[] = ['account' => $accountId, 'title' => $title, 'candidates' => $candidates];

        return $this->verdict;
    }
}
