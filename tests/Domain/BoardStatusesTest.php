<?php

declare(strict_types=1);

namespace Votepit\Tests\Domain;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Votepit\Domain\BoardStatuses;
use Votepit\Domain\StatusLabel;
use Votepit\Domain\StatusService;

/**
 * Status categories + owner-defined statuses (broaden-use-cases issue 03,
 * migrations/0073_create_board_statuses.sql): the per-board registry.
 */
final class BoardStatusesTest extends TestCase
{
    public function test_defaults_are_the_six_system_statuses_in_canonical_order(): void
    {
        $statuses = BoardStatuses::defaults();

        self::assertFalse($statuses->isCustomized());
        self::assertSame(StatusService::VALID_STATUSES, $statuses->keys());
        foreach ($statuses->all() as $status) {
            self::assertTrue($status->isSystem);
            self::assertSame($status->key, $status->category);
            self::assertFalse($status->isHidden);
            self::assertSame(
                !in_array($status->key, ['done', 'declined', 'archived'], true),
                $status->allowVotes,
                'votes close on decided ideas by default (owner decision 2026-10-26)',
            );
            self::assertTrue($status->allowComments);
        }
    }

    public function test_default_allow_votes_closes_only_done_declined_and_archived(): void
    {
        foreach (StatusService::VALID_STATUSES as $category) {
            self::assertSame(
                !in_array($category, ['done', 'declined', 'archived'], true),
                BoardStatuses::defaultAllowVotes($category),
                $category,
            );
        }
    }

    /** A missing or tampered system row falls back to the same default as defaults(). */
    public function test_fallback_for_a_missing_system_row_keeps_the_default_vote_flag(): void
    {
        $statuses = BoardStatuses::fromRows([]);

        self::assertSame(StatusService::VALID_STATUSES, $statuses->keys());
        self::assertTrue($statuses->find('open')?->allowVotes);
        self::assertTrue($statuses->find('in_progress')?->allowVotes);
        self::assertFalse($statuses->find('done')?->allowVotes);
        self::assertFalse($statuses->find('declined')?->allowVotes);
        self::assertFalse($statuses->find('archived')?->allowVotes);
    }

    /** The default registry must reproduce StatusService's graph exactly. */
    public function test_default_transitions_are_identical_to_status_service(): void
    {
        $statuses = BoardStatuses::defaults();
        $service  = new StatusService();

        foreach (StatusService::VALID_STATUSES as $from) {
            foreach (StatusService::VALID_STATUSES as $to) {
                self::assertSame(
                    $service->canTransition($from, $to),
                    $statuses->canTransition($from, $to),
                    "{$from} → {$to}",
                );
            }
            self::assertSame($service->targetsFrom($from), $statuses->allowedNext($from), "allowed_next of {$from}");
        }
    }

    public function test_unknown_keys_never_transition(): void
    {
        $statuses = BoardStatuses::defaults();

        self::assertFalse($statuses->canTransition('open', 'shipped'));
        self::assertFalse($statuses->canTransition('shipped', 'open'));
        self::assertSame([], $statuses->allowedNext('shipped'));
    }

    public function test_custom_status_inherits_the_category_graph_and_moves_freely_inside_its_category(): void
    {
        $statuses = BoardStatuses::fromRows([
            ...$this->systemRows(),
            $this->row('s1', 'done', ['label_de' => 'Beschlossen', 'position' => 10]),
        ]);

        self::assertTrue($statuses->isCustomized());
        self::assertSame('done', $statuses->categoryOf('s1'));
        // inbox → completed is allowed, so open → s1 is too.
        self::assertTrue($statuses->canTransition('open', 's1'));
        // declined → completed is not.
        self::assertFalse($statuses->canTransition('declined', 's1'));
        // Inside one category any status may move to any other.
        self::assertTrue($statuses->canTransition('done', 's1'));
        self::assertTrue($statuses->canTransition('s1', 'done'));
        // completed → archived carries over to the custom status.
        self::assertTrue($statuses->canTransition('s1', 'archived'));
        // Ordered by category, then position: s1 sits right after done.
        self::assertSame(['open', 'planned', 'in_progress', 'done', 's1', 'declined', 'archived'], $statuses->keys());
        self::assertSame(['done', 's1'], $statuses->keysInCategory('done'));
    }

    public function test_hidden_status_is_not_assignable_but_can_be_left(): void
    {
        $rows    = $this->systemRows();
        $rows[1] = $this->row('planned', 'planned', ['is_system' => 1, 'is_hidden' => 1]);
        $statuses = BoardStatuses::fromRows($rows);

        self::assertTrue($statuses->find('planned')?->isHidden);
        self::assertFalse($statuses->canTransition('open', 'planned'));
        self::assertNotContains('planned', $statuses->allowedNext('open'));
        self::assertTrue($statuses->canTransition('planned', 'in_progress'));
        // Self → self stays an idempotent no-op.
        self::assertTrue($statuses->canTransition('planned', 'planned'));
    }

    public function test_open_can_never_be_hidden_even_from_a_tampered_row(): void
    {
        $rows    = $this->systemRows();
        $rows[0] = $this->row('open', 'open', ['is_system' => 1, 'is_hidden' => 1]);

        self::assertFalse(BoardStatuses::fromRows($rows)->find('open')?->isHidden);
    }

    public function test_missing_system_rows_fall_back_to_defaults_and_bad_rows_are_dropped(): void
    {
        $statuses = BoardStatuses::fromRows([
            $this->row('s1', 'bogus'),                       // unknown category
            $this->row('<b>', 'done'),                       // invalid key
            $this->row('planned', 'done', ['is_system' => 1]), // system row with a foreign category
            $this->row('s2', 'planned', ['label_en' => '<script>x</script>']),
        ]);

        self::assertSame(['open', 'planned', 's2', 'in_progress', 'done', 'declined', 'archived'], $statuses->keys());
        self::assertSame('planned', $statuses->categoryOf('planned'));
        // An invalid stored label is dropped, never rendered.
        self::assertNull($statuses->find('s2')?->labelEn);
    }

    public function test_label_resolution_chain(): void
    {
        $statuses = BoardStatuses::fromRows([
            ...$this->systemRows(),
            $this->row('s1', 'done', ['label_de' => 'Beschlossen']),
            $this->row('s2', 'done', ['label_en' => 'Agreed']),
        ]);
        $renamedRows    = $this->systemRows();
        $renamedRows[1] = $this->row('planned', 'planned', ['is_system' => 1, 'label_en' => 'Up next']);
        $renamed        = BoardStatuses::fromRows($renamedRows);

        // System status: override[L] → default[L].
        self::assertSame('Up next', $renamed->label('planned', 'en'));
        self::assertSame('Geplant', $renamed->label('planned', 'de'));
        self::assertSame('Planned', $statuses->label('planned', 'en'));
        // Custom status: override[L] → en → de.
        self::assertSame('Beschlossen', $statuses->label('s1', 'en'));
        self::assertSame('Agreed', $statuses->label('s2', 'de'));
        self::assertSame('Agreed', $statuses->label('s2', 'fr'));
        // Unknown key: the key itself.
        self::assertSame('zz', $statuses->label('zz', 'en'));
    }

    public function test_notification_label_keeps_the_raw_slug_for_an_unrenamed_system_status(): void
    {
        $statuses = BoardStatuses::defaults();
        self::assertSame('in_progress', $statuses->notificationLabel('in_progress'));

        $rows    = $this->systemRows();
        $rows[2] = $this->row('in_progress', 'in_progress', ['is_system' => 1, 'label_de' => 'Läuft']); // export-ok: comment-language
        self::assertSame('Läuft', BoardStatuses::fromRows($rows)->notificationLabel('in_progress')); // export-ok: comment-language
    }

    public function test_to_array_shape(): void
    {
        $first = BoardStatuses::defaults()->toArray()[0];

        self::assertSame([
            'key'            => 'open',
            'category'       => 'open',
            'category_name'  => 'inbox',
            'is_system'      => true,
            'label_de'       => null,
            'label_en'       => null,
            'is_hidden'      => false,
            'allow_votes'    => true,
            'allow_comments' => true,
            'idea_count'     => null,
        ], $first);
    }

    public function test_category_names(): void
    {
        self::assertSame('inbox', BoardStatuses::categoryName('open'));
        self::assertSame('active', BoardStatuses::categoryName('in_progress'));
        self::assertSame('completed', BoardStatuses::categoryName('done'));
        self::assertSame('archived', BoardStatuses::categoryName('archived'));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidLabelProvider(): iterable
    {
        yield 'too long' => [str_repeat('a', StatusLabel::MAX_LENGTH + 1)];
        yield 'html tag' => ['<b>Soon</b>'];
        yield 'lone angle bracket' => ['a > b'];
        yield 'newline' => ["Line\nbreak"];
        yield 'bidi override' => ["Soon\u{202E}"];
        yield 'zero width' => ["So\u{200B}on"];
        yield 'event handler' => ['x onerror=alert(1)'];
        yield 'javascript scheme' => ['javascript:alert(1)'];
        yield 'invalid utf-8' => ["\xC3\x28"];
    }

    #[DataProvider('invalidLabelProvider')]
    public function test_invalid_labels_are_rejected(string $label): void
    {
        $error = StatusLabel::error($label);

        self::assertNotNull($error);
        self::assertStringNotContainsString($label, $error);
    }

    public function test_label_bounds(): void
    {
        self::assertNull(StatusLabel::error(str_repeat('ä', StatusLabel::MAX_LENGTH))); // export-ok: comment-language
        self::assertNull(StatusLabel::error('  Findet statt  '));
        self::assertSame('Findet statt', StatusLabel::normalize('  Findet statt  '));
        self::assertNull(StatusLabel::normalize('   '));
    }

    /** @return list<array<string, mixed>> */
    private function systemRows(): array
    {
        $rows = [];
        foreach (StatusService::VALID_STATUSES as $i => $key) {
            $rows[] = $this->row($key, $key, ['is_system' => 1, 'position' => $i]);
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function row(string $key, string $category, array $overrides = []): array
    {
        return array_merge([
            'status_key'     => $key,
            'category'       => $category,
            'label_de'       => null,
            'label_en'       => null,
            'position'       => 0,
            'is_hidden'      => 0,
            'allow_votes'    => 1,
            'allow_comments' => 1,
            'is_system'      => 0,
        ], $overrides);
    }
}
