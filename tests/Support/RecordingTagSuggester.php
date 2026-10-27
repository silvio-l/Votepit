<?php

declare(strict_types=1);

namespace Votepit\Tests\Support;

use Votepit\Extension\TagSuggester;
use Votepit\Extension\TagSuggestionAvailability;

/**
 * TagSuggester test double (P-35): returns a fixed availability and answer
 * and records every suggest() call, so core tests can assert exactly which
 * title, body and tag names would leave the installation.
 */
final class RecordingTagSuggester implements TagSuggester
{
    /** @var list<array{account: int, title: string, body: string, tags: list<array{id: int, name: string}>}> */
    public array $calls = [];

    /** @param list<int>|null $answer */
    public function __construct(
        public ?array $answer = [],
        public TagSuggestionAvailability $availability = TagSuggestionAvailability::Available,
    ) {}

    public function availability(int $accountId): TagSuggestionAvailability
    {
        return $this->availability;
    }

    public function suggest(int $accountId, string $title, string $body, array $tags): ?array
    {
        $this->calls[] = ['account' => $accountId, 'title' => $title, 'body' => $body, 'tags' => $tags];

        return $this->answer;
    }
}
