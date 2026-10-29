<?php

declare(strict_types=1);

namespace Votepit\Domain;

/**
 * Optional per-board time windows for submitting ideas and for voting
 * (broaden-use-cases issue 04, migrations/0082_add_board_phase_windows.sql).
 *
 * Pure computation over the board row + an injected "now" (Support\Clock) —
 * the server is authoritative, the SPA only renders the computed state.
 * A NULL bound means "no limit on that side"; a board without any bound is
 * open for everything, exactly as before the feature existed.
 *
 * Boundaries: at exactly opens_at the window is open, at exactly closes_at
 * it is closed (half-open interval [opens_at, closes_at)).
 *
 * Effective rule (callers combine it): submissions = submissions window
 * (owner/admin exempt, IdeaCreateAction); votes = voting window AND the
 * idea status' allow_votes flag (migration 0073, VoteAction); comments are
 * never governed by a window. Deliberately separate from the plan freeze
 * (board_frozen) — own error keys, own code path.
 *
 * Bound: SPA submit/vote, the embeddable widget (it reuses the same
 * IdeaCreateAction/VoteAction instances) and Agent-API/MCP tokens.
 * Not bound: the admin import (bulk path, cp-16), moderation actions such
 * as approving a pending idea or merging duplicates (cp-10), and reads.
 */
final readonly class BoardPhases
{
    public const OPEN     = 'open';
    public const UPCOMING = 'upcoming';
    public const CLOSED   = 'closed';

    /** Board columns, in the order the admin form/payload uses them. */
    public const COLUMNS = ['submissions_opens_at', 'submissions_closes_at', 'voting_opens_at', 'voting_closes_at'];

    /** DB storage format (DATETIME, always UTC). */
    public const DB_FORMAT = 'Y-m-d H:i:s';

    /**
     * Accepted input: ISO 8601 date-time WITH an explicit offset (Z or ±hh:mm)
     * — a value without a zone would be ambiguous, so it is rejected.
     */
    private const INPUT_PATTERN = '/^(\d{4}-\d{2}-\d{2})T\d{2}:\d{2}(?::\d{2}(?:\.\d{1,6})?)?(?:Z|[+-]\d{2}:\d{2})$/';

    /** @param array<string, \DateTimeImmutable|false|null> $bounds false = unreadable stored value */
    private function __construct(
        private array $bounds,
        private \DateTimeImmutable $now,
    ) {}

    /** @param array<string, mixed> $board a board row (missing columns = no bound) */
    public static function fromBoard(array $board, \DateTimeImmutable $now): self
    {
        $bounds = [];
        foreach (self::COLUMNS as $column) {
            $raw = $board[$column] ?? null;
            if ($raw === null || $raw === '') {
                $bounds[$column] = null;
                continue;
            }
            $parsed          = is_string($raw) ? \DateTimeImmutable::createFromFormat('!' . self::DB_FORMAT, $raw, new \DateTimeZone('UTC')) : false;
            $bounds[$column] = $parsed instanceof \DateTimeImmutable && $parsed->format(self::DB_FORMAT) === $raw ? $parsed : false;
        }

        return new self($bounds, $now);
    }

    /** True when at least one bound is set (a default board answers false). */
    public function isConfigured(): bool
    {
        foreach ($this->bounds as $bound) {
            if ($bound !== null) {
                return true;
            }
        }

        return false;
    }

    /** @return 'submissions_closed'|'submissions_not_open_yet'|null null = allowed */
    public function submissionRejection(): ?string
    {
        return match ($this->state('submissions')) {
            self::UPCOMING => 'submissions_not_open_yet',
            self::CLOSED   => 'submissions_closed',
            default        => null,
        };
    }

    /** @return 'voting_closed'|'voting_not_open_yet'|null null = allowed by the window */
    public function votingRejection(): ?string
    {
        return match ($this->state('voting')) {
            self::UPCOMING => 'voting_not_open_yet',
            self::CLOSED   => 'voting_closed',
            default        => null,
        };
    }

    /**
     * Additive board payload: computed state + bounds as ISO 8601 UTC.
     *
     * @return array{submissions: array{state: string, opens_at: ?string, closes_at: ?string}, voting: array{state: string, opens_at: ?string, closes_at: ?string}}
     */
    public function toArray(): array
    {
        return [
            'submissions' => $this->phaseArray('submissions'),
            'voting'      => $this->phaseArray('voting'),
        ];
    }

    /**
     * Strict parser for admin input — see INPUT_PATTERN. Returns the instant
     * in UTC, or null for anything ambiguous/invalid (incl. impossible dates
     * such as Feb 30, which PHP would otherwise silently roll over).
     */
    public static function parseInstant(string $raw): ?\DateTimeImmutable
    {
        if (preg_match(self::INPUT_PATTERN, $raw, $m) !== 1) {
            return null;
        }

        try {
            $parsed = new \DateTimeImmutable($raw);
        } catch (\Exception) {
            return null;
        }

        if ($parsed->format('Y-m-d') !== $m[1]) {
            return null;
        }

        return $parsed->setTimezone(new \DateTimeZone('UTC'));
    }

    /** @return array{state: string, opens_at: ?string, closes_at: ?string} */
    private function phaseArray(string $phase): array
    {
        return [
            'state'     => $this->state($phase),
            'opens_at'  => $this->iso($this->bounds[$phase . '_opens_at'] ?? null),
            'closes_at' => $this->iso($this->bounds[$phase . '_closes_at'] ?? null),
        ];
    }

    private function state(string $phase): string
    {
        $opens  = $this->bounds[$phase . '_opens_at'] ?? null;
        $closes = $this->bounds[$phase . '_closes_at'] ?? null;

        // Fail-secure: an unreadable stored bound closes the phase.
        if ($opens === false || $closes === false) {
            return self::CLOSED;
        }
        if ($opens !== null && $this->now < $opens) {
            return self::UPCOMING;
        }
        if ($closes !== null && $this->now >= $closes) {
            return self::CLOSED;
        }

        return self::OPEN;
    }

    private function iso(\DateTimeImmutable|false|null $bound): ?string
    {
        return $bound instanceof \DateTimeImmutable ? $bound->format('Y-m-d\TH:i:s\Z') : null;
    }
}
