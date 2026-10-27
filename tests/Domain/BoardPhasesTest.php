<?php

declare(strict_types=1);

namespace Votepit\Tests\Domain;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Votepit\Domain\BoardPhases;

/**
 * Optional submission/voting windows (broaden-use-cases issue 04): the pure
 * phase computation, including the exact boundaries (opens_at is already
 * open, closes_at is already closed) with an injected "now".
 */
final class BoardPhasesTest extends TestCase
{
    private function at(string $utc): \DateTimeImmutable
    {
        return new \DateTimeImmutable($utc, new \DateTimeZone('UTC'));
    }

    public function test_board_without_windows_is_open_and_unconfigured(): void
    {
        $phases = BoardPhases::fromBoard(['id' => 1], $this->at('2026-04-01 12:00:00'));

        self::assertFalse($phases->isConfigured());
        self::assertNull($phases->submissionRejection());
        self::assertNull($phases->votingRejection());
        self::assertSame([
            'submissions' => ['state' => 'open', 'opens_at' => null, 'closes_at' => null],
            'voting'      => ['state' => 'open', 'opens_at' => null, 'closes_at' => null],
        ], $phases->toArray());
    }

    /** @return iterable<string, array{string, string, ?string}> */
    public static function votingBoundaryProvider(): iterable
    {
        yield 'one second before opening'  => ['2026-04-01 07:59:59', 'upcoming', 'voting_not_open_yet'];
        yield 'exactly at opening'         => ['2026-04-01 08:00:00', 'open', null];
        yield 'one second before closing'  => ['2026-04-30 21:59:59', 'open', null];
        yield 'exactly at closing'         => ['2026-04-30 22:00:00', 'closed', 'voting_closed'];
        yield 'long after closing'         => ['2027-01-01 00:00:00', 'closed', 'voting_closed'];
    }

    #[DataProvider('votingBoundaryProvider')]
    public function test_voting_window_boundaries(string $now, string $state, ?string $rejection): void
    {
        $phases = BoardPhases::fromBoard([
            'voting_opens_at'  => '2026-04-01 08:00:00',
            'voting_closes_at' => '2026-04-30 22:00:00',
        ], $this->at($now));

        self::assertTrue($phases->isConfigured());
        self::assertSame($state, $phases->toArray()['voting']['state']);
        self::assertSame($rejection, $phases->votingRejection());
        // Submissions are independent of the voting window.
        self::assertNull($phases->submissionRejection());
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function submissionBoundaryProvider(): iterable
    {
        yield 'before opening' => ['2026-03-01 09:59:59', 'submissions_not_open_yet'];
        yield 'at opening'     => ['2026-03-01 10:00:00', null];
        yield 'at closing'     => ['2026-03-31 22:00:00', 'submissions_closed'];
    }

    #[DataProvider('submissionBoundaryProvider')]
    public function test_submission_window_boundaries(string $now, ?string $rejection): void
    {
        $phases = BoardPhases::fromBoard([
            'submissions_opens_at'  => '2026-03-01 10:00:00',
            'submissions_closes_at' => '2026-03-31 22:00:00',
        ], $this->at($now));

        self::assertSame($rejection, $phases->submissionRejection());
        self::assertNull($phases->votingRejection());
    }

    public function test_one_sided_windows(): void
    {
        $onlyClose = BoardPhases::fromBoard(['voting_closes_at' => '2026-04-30 22:00:00'], $this->at('2020-01-01 00:00:00'));
        self::assertNull($onlyClose->votingRejection());

        $onlyOpen = BoardPhases::fromBoard(['submissions_opens_at' => '2026-03-01 10:00:00'], $this->at('2099-01-01 00:00:00'));
        self::assertNull($onlyOpen->submissionRejection());
    }

    public function test_payload_dates_are_iso_utc(): void
    {
        $phases = BoardPhases::fromBoard([
            'voting_opens_at'  => '2026-04-01 08:00:00',
            'voting_closes_at' => '2026-04-30 22:00:00',
        ], $this->at('2026-03-01 00:00:00'));

        self::assertSame(
            ['state' => 'upcoming', 'opens_at' => '2026-04-01T08:00:00Z', 'closes_at' => '2026-04-30T22:00:00Z'],
            $phases->toArray()['voting'],
        );
    }

    public function test_unreadable_stored_value_fails_secure_as_closed(): void
    {
        $phases = BoardPhases::fromBoard(['voting_closes_at' => 'not-a-date'], $this->at('2026-03-01 00:00:00'));

        self::assertSame('voting_closed', $phases->votingRejection());
    }

    public function test_parse_instant_accepts_iso_with_offset_and_normalises_to_utc(): void
    {
        self::assertSame('2026-03-31 22:00:00', BoardPhases::parseInstant('2026-04-01T00:00:00+02:00')?->format('Y-m-d H:i:s'));
        self::assertSame('2026-03-31 22:00:00', BoardPhases::parseInstant('2026-03-31T22:00:00.000Z')?->format('Y-m-d H:i:s'));
        self::assertSame('UTC', BoardPhases::parseInstant('2026-03-31T22:00:00Z')?->getTimezone()->getName());
    }

    /** @return iterable<string, array{string}> */
    public static function invalidInstantProvider(): iterable
    {
        yield 'garbage'            => ['tomorrow-ish'];
        yield 'relative format'    => ['+1 day'];
        yield 'no time zone'       => ['2026-04-01T00:00:00'];
        yield 'date only'          => ['2026-04-01'];
        yield 'impossible date'    => ['2026-02-30T00:00:00Z'];
        yield 'markup'             => ['<script>'];
    }

    #[DataProvider('invalidInstantProvider')]
    public function test_parse_instant_rejects_ambiguous_or_invalid_input(string $raw): void
    {
        self::assertNull(BoardPhases::parseInstant($raw));
    }
}
