<?php

declare(strict_types=1);

namespace Votepit\Domain;

/**
 * Per-board status registry (broaden-use-cases issue 03,
 * migrations/0073_create_board_statuses.sql) — the single source of truth
 * for which statuses a board has, their labels, flags and transitions.
 *
 * Six fixed CATEGORIES = the six canonical status slugs of StatusService
 * (open/inbox, planned, in_progress/active, done/completed, declined,
 * archived). Every status belongs to one category; behaviour hangs on the
 * category, never on a label:
 *   - transitions are defined between categories (StatusService's graph);
 *     inside one category any status may move to any other;
 *   - a hidden status can be left but never newly assigned;
 *   - `open` can never be hidden (every new idea starts there).
 *
 * A board without rows has exactly the six system defaults, and then every
 * answer of this class is identical to StatusService (pinned by
 * BoardStatusesTest).
 *
 * fromRows() is the fail-secure read path: rows with an unknown category or
 * an invalid key are dropped, a missing or tampered system row falls back to
 * its default, an invalid stored label is dropped (never rendered).
 */
final readonly class BoardStatuses
{
    /** Hard cap on statuses per board (system + custom). */
    public const MAX_STATUSES = 12;

    /** Allowed key format (system slugs and generated `s<base36>` keys). */
    public const KEY_PATTERN = '/^[a-z0-9_]{1,16}$/';

    /** Human-facing category names (API `status_category`). */
    private const CATEGORY_NAMES = [
        'open'        => 'inbox',
        'planned'     => 'planned',
        'in_progress' => 'active',
        'done'        => 'completed',
        'declined'    => 'declined',
        'archived'    => 'archived',
    ];

    /**
     * Categories whose system status closes voting by default (owner decision
     * 2026-10-26): a decided, declined or archived idea takes no votes until an
     * admin re-opens the status. Custom statuses are not affected by this list.
     */
    private const CLOSED_FOR_VOTES_BY_DEFAULT = ['done', 'declined', 'archived'];

    /** Server-side default labels of the system statuses (the SPA has its own i18n). */
    private const DEFAULT_LABELS = [
        'en' => [
            'open'        => 'Open',
            'planned'     => 'Planned',
            'in_progress' => 'In progress',
            'done'        => 'Done',
            'declined'    => 'Declined',
            'archived'    => 'Archived',
        ],
        'de' => [
            'open'        => 'Offen',
            'planned'     => 'Geplant',
            'in_progress' => 'In Arbeit',
            'done'        => 'Erledigt',
            'declined'    => 'Abgelehnt',
            'archived'    => 'Archiviert',
        ],
    ];

    /** @param list<BoardStatus> $statuses ordered */
    private function __construct(
        private array $statuses,
        private bool $customized,
    ) {}

    public static function defaults(): self
    {
        $statuses = [];
        foreach (StatusService::VALID_STATUSES as $i => $key) {
            $statuses[] = self::systemStatus($key, $i);
        }

        return new self($statuses, false);
    }

    /**
     * Tolerant parsing of board_statuses rows (see class doc).
     *
     * @param list<array<string, mixed>> $rows
     */
    public static function fromRows(array $rows): self
    {
        /** @var array<string, BoardStatus> $system */
        $system = [];
        /** @var list<BoardStatus> $custom */
        $custom = [];
        $seen   = [];

        foreach ($rows as $row) {
            $key      = is_string($row['status_key'] ?? null) ? $row['status_key'] : '';
            $category = is_string($row['category'] ?? null) ? $row['category'] : '';
            if (preg_match(self::KEY_PATTERN, $key) !== 1 || !isset(self::CATEGORY_NAMES[$category]) || isset($seen[$key])) {
                continue;
            }

            $isSystem = (bool) ($row['is_system'] ?? false);
            // A system status keeps its canonical slug as key and its category;
            // a custom status may never squat on a canonical slug.
            if ($isSystem !== isset(self::CATEGORY_NAMES[$key]) || ($isSystem && $key !== $category)) {
                continue;
            }

            $seen[$key] = true;
            $status     = new BoardStatus(
                $key,
                $category,
                $isSystem,
                StatusLabel::normalize($row['label_de'] ?? null),
                StatusLabel::normalize($row['label_en'] ?? null),
                (int) ($row['position'] ?? 0),
                $key !== 'open' && (bool) ($row['is_hidden'] ?? false),
                (bool) ($row['allow_votes'] ?? true),
                (bool) ($row['allow_comments'] ?? true),
                isset($row['idea_count']) && is_numeric($row['idea_count']) ? (int) $row['idea_count'] : null,
            );

            if ($isSystem) {
                $system[$key] = $status;
            } else {
                $custom[] = $status;
            }
        }

        usort($custom, static fn (BoardStatus $a, BoardStatus $b): int => [$a->position, $a->key] <=> [$b->position, $b->key]);

        $ordered = [];
        foreach (StatusService::VALID_STATUSES as $i => $category) {
            $ordered[] = $system[$category] ?? self::systemStatus($category, $i);
            foreach ($custom as $status) {
                if ($status->category === $category) {
                    $ordered[] = $status;
                }
            }
        }

        return new self($ordered, true);
    }

    /**
     * Default `allow_votes` of a system status of the given category. The
     * single source for the code defaults (defaults(), fromRows() fallback)
     * and for the rows written on materialisation (BoardStatusRepository).
     */
    public static function defaultAllowVotes(string $category): bool
    {
        return !in_array($category, self::CLOSED_FOR_VOTES_BY_DEFAULT, true);
    }

    private static function systemStatus(string $category, int $position): BoardStatus
    {
        return new BoardStatus(
            $category,
            $category,
            true,
            position: $position,
            allowVotes: self::defaultAllowVotes($category),
        );
    }

    public static function categoryName(string $category): string
    {
        return self::CATEGORY_NAMES[$category] ?? $category;
    }

    public static function isCategory(string $value): bool
    {
        return isset(self::CATEGORY_NAMES[$value]);
    }

    /**
     * Server-side label of a status row (category + optional overrides),
     * without a loaded registry — used to decorate idea rows that carry the
     * joined board_statuses columns (IdeaRepository).
     */
    public static function resolveLabel(string $key, string $category, ?string $labelDe, ?string $labelEn, string $locale): string
    {
        $override = match ($locale) {
            'de'    => $labelDe,
            'en'    => $labelEn,
            default => null,
        };
        if ($override !== null) {
            return $override;
        }

        $isSystem = $key === $category && isset(self::CATEGORY_NAMES[$key]);
        if ($isSystem && isset(self::DEFAULT_LABELS[$locale][$key])) {
            return self::DEFAULT_LABELS[$locale][$key];
        }

        return $labelEn ?? $labelDe ?? ($isSystem ? self::DEFAULT_LABELS['en'][$key] : $key);
    }

    public function isCustomized(): bool
    {
        return $this->customized;
    }

    /** @return list<BoardStatus> */
    public function all(): array
    {
        return $this->statuses;
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_map(static fn (BoardStatus $s): string => $s->key, $this->statuses);
    }

    /** @return list<string> */
    public function keysInCategory(string $category): array
    {
        $keys = [];
        foreach ($this->statuses as $status) {
            if ($status->category === $category) {
                $keys[] = $status->key;
            }
        }

        return $keys;
    }

    public function find(string $key): ?BoardStatus
    {
        foreach ($this->statuses as $status) {
            if ($status->key === $key) {
                return $status;
            }
        }

        return null;
    }

    public function categoryOf(string $key): ?string
    {
        return $this->find($key)?->category;
    }

    /**
     * Self → self is an idempotent no-op (true, for any known key). Otherwise
     * the target must exist and not be hidden, and either share the source's
     * category or be reachable via the category graph.
     */
    public function canTransition(string $from, string $to): bool
    {
        $source = $this->find($from);
        $target = $this->find($to);
        if (!$source instanceof BoardStatus || !$target instanceof BoardStatus) {
            return false;
        }
        if ($from === $to) {
            return true;
        }
        if ($target->isHidden) {
            return false;
        }

        return $source->category === $target->category
            || in_array($target->category, (new StatusService())->targetsFrom($source->category), true);
    }

    /**
     * Keys an idea in $from may move to (excluding $from itself). For the
     * default registry the order is StatusService's target order, otherwise
     * registry order.
     *
     * @return list<string>
     */
    public function allowedNext(string $from): array
    {
        if (!$this->customized) {
            return $this->find($from) instanceof BoardStatus ? (new StatusService())->targetsFrom($from) : [];
        }

        $next = [];
        foreach ($this->statuses as $status) {
            if ($status->key !== $from && $this->canTransition($from, $status->key)) {
                $next[] = $status->key;
            }
        }

        return $next;
    }

    /** Label for $locale (override → system default → en → de → key). */
    public function label(string $key, string $locale): string
    {
        $status = $this->find($key);
        if (!$status instanceof BoardStatus) {
            return $key;
        }

        return self::resolveLabel($status->key, $status->category, $status->labelDe, $status->labelEn, $locale);
    }

    /**
     * Label for the server-generated (English) notification text. An
     * un-renamed system status keeps its raw slug, exactly as before this
     * feature ("… is now in_progress."); otherwise en → de override.
     */
    public function notificationLabel(string $key): string
    {
        $status = $this->find($key);
        if (!$status instanceof BoardStatus) {
            return $key;
        }
        if ($status->isSystem && $status->labelEn === null && $status->labelDe === null) {
            return $status->key;
        }

        // No recipient language is known: any owner-chosen word beats the
        // English default (a board that only renamed the German label keeps that label).
        return $status->labelEn ?? $status->labelDe ?? $this->label($key, 'en');
    }

    /** @return list<array<string, mixed>> */
    public function toArray(): array
    {
        return array_map(static fn (BoardStatus $s): array => $s->toArray(), $this->statuses);
    }
}
