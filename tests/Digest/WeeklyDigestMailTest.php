<?php

declare(strict_types=1);

namespace Votepit\Tests\Digest;

use PHPUnit\Framework\TestCase;
use Votepit\Digest\WeeklyDigestMail;

/**
 * Layout rules of the redesigned weekly owner digest (variant A): one task
 * first with the single button, ordered board sections, ideas deduplicated,
 * no invented "0 %", account names only when ambiguous.
 *
 * @phpstan-import-type DigestSection from WeeklyDigestMail
 */
final class WeeklyDigestMailTest extends TestCase
{
    /**
     * @param array<string, mixed> $overrides
     * @return DigestSection
     */
    private function section(string $name, array $overrides = []): array
    {
        $slug = strtolower(str_replace(' ', '-', $name));

        /** @var DigestSection */
        return array_replace([
            'account_name'    => 'Acme',
            'board_name'      => $name,
            'board_url'       => 'https://app.test/acme/' . $slug,
            'admin_url'       => 'https://app.test/acme/admin/boards/' . $slug,
            'stats'           => ['weekly_votes' => 4, 'weekly_new_ideas' => 1, 'avg_consensus' => 70, 'total_ideas' => 9],
            'highlights'      => ['new_ideas' => [], 'top' => [], 'most_rejected' => [], 'most_polarising' => []],
            'awaiting_answer' => 0,
        ], $overrides);
    }

    /**
     * @param list<DigestSection> $sections
     * @return array{subject: string, text: string, html: string}
     */
    private function render(array $sections, string $lang = 'en', string $start = '2026-10-19', string $end = '2026-10-25'): array
    {
        $mail = WeeklyDigestMail::render($lang, $sections, 'https://app.test/', 'https://app.test/profile', 'https://app.test/digest/unsubscribe?token=t', new \DateTimeImmutable($start), new \DateTimeImmutable($end));

        return ['subject' => $mail['subject'], 'text' => $mail['text'], 'html' => $mail['html']];
    }

    public function test_waiting_ideas_become_heading_subject_and_the_single_early_button(): void
    {
        $mail = $this->render([
            $this->section('Quiet board'),
            $this->section('Busy board', ['awaiting_answer' => 2]),
        ]);

        self::assertSame('Votepit: 2 ideas are waiting for your answer', $mail['subject']);
        self::assertStringContainsString('<h1', $mail['html']);
        self::assertStringContainsString('2 ideas are waiting for your answer</h1>', $mail['html']);
        // Exactly one button, and it sits before the first board section.
        self::assertSame(1, substr_count($mail['html'], 'Answer the ideas</a>'));
        self::assertLessThan(strpos($mail['html'], '>Busy board</a>'), strpos($mail['html'], 'Answer the ideas</a>'));
        self::assertStringContainsString("Answer the ideas:\nhttps://app.test/acme/admin/boards/busy-board", $mail['text']);
        // The board waiting for an answer comes first.
        self::assertLessThan(strpos($mail['text'], "Quiet board\n"), strpos($mail['text'], "Busy board\n"));
    }

    public function test_only_the_first_and_waiting_boards_get_the_full_section(): void
    {
        $mail = $this->render([
            $this->section('Alpha', [
                'stats'      => ['weekly_votes' => 9, 'weekly_new_ideas' => 3, 'avg_consensus' => 64, 'total_ideas' => 20],
                'highlights' => ['new_ideas' => [['id' => 1, 'title' => 'A1'], ['id' => 2, 'title' => 'A2']], 'top' => [], 'most_rejected' => [], 'most_polarising' => []],
            ]),
            $this->section('Beta', [
                'highlights' => ['new_ideas' => [['id' => 3, 'title' => 'B1'], ['id' => 4, 'title' => 'B2']], 'top' => [], 'most_rejected' => [], 'most_polarising' => []],
            ]),
        ], 'de');

        // Full section: label + every row; compact section: one row, no label.
        self::assertSame(1, substr_count($mail['text'], 'Ideen im Blick'));
        self::assertStringContainsString("A2\n", $mail['text']);
        self::assertStringContainsString("B1\n", $mail['text']);
        self::assertStringNotContainsString("B2\n", $mail['text']);
        self::assertStringContainsString('3 neue Ideen · 9 Stimmen · 64 % Zustimmung', $mail['text']); // export-ok: comment-language
        self::assertStringContainsString('1 neue Idee und 4 Stimmen diese Woche · Zustimmung 70 %', $mail['text']); // export-ok: comment-language
        self::assertSame('Deine Votepit-Woche: 4 neue Ideen', $mail['subject']); // export-ok: comment-language
        self::assertStringContainsString('19.–25. Oktober 2026', $mail['text']); // export-ok: comment-language
    }

    public function test_ideas_appear_once_per_board_with_up_and_down_votes(): void
    {
        $same = ['id' => 7, 'title' => 'Dark mode', 'up_count' => 5, 'down_count' => 2];
        $mail = $this->render([$this->section('Board', ['highlights' => [
            'new_ideas'       => [['id' => 7, 'title' => 'Dark mode'], ['id' => 8, 'title' => 'Fresh one']],
            'top'             => [$same],
            'most_rejected'   => [$same],
            'most_polarising' => [$same],
        ]])]);

        self::assertSame(1, substr_count($mail['text'], "Dark mode\n"));
        self::assertStringContainsString("Dark mode\nMost voted · 5 for / 2 against\nhttps://app.test/acme/board/idea/7", $mail['text']);
        self::assertStringContainsString("Fresh one\nNew this week", $mail['text']);
    }

    public function test_a_board_without_votes_shows_no_approval_and_an_idle_board_says_so(): void
    {
        $mail = $this->render([
            $this->section('Fresh', ['stats' => ['weekly_votes' => 0, 'weekly_new_ideas' => 2, 'avg_consensus' => 0, 'total_ideas' => 2]]),
            $this->section('Idle', ['stats' => ['weekly_votes' => 0, 'weekly_new_ideas' => 0, 'avg_consensus' => 0, 'total_ideas' => 3]]),
        ]);

        self::assertStringNotContainsString('0%', $mail['text']);
        self::assertStringNotContainsString('approval', strtolower(explode('Weekly figures', $mail['text'])[0]));
        self::assertStringContainsString("Idle\nhttps://app.test/acme/idle\n\nNo new ideas or votes this week.", $mail['text']);
    }

    public function test_account_name_is_added_only_when_board_names_collide(): void
    {
        $mail = $this->render([
            $this->section('Feedback', ['account_name' => 'Acme']),
            $this->section('Feedback', ['account_name' => 'Globex', 'board_url' => 'https://app.test/globex/feedback']),
            $this->section('Roadmap'),
        ]);

        self::assertStringContainsString('Feedback (Acme)', $mail['text']);
        self::assertStringContainsString('Feedback (Globex)', $mail['text']);
        self::assertStringNotContainsString('Roadmap (Acme)', $mail['text']);
    }

    public function test_tenant_content_is_escaped_in_html(): void
    {
        $mail = $this->render([$this->section('<b>Board</b>', ['highlights' => [
            'new_ideas' => [], 'most_rejected' => [], 'most_polarising' => [],
            'top'       => [['id' => 1, 'title' => '<script>x</script>', 'up_count' => 1, 'down_count' => 0]],
        ]])]);

        self::assertStringNotContainsString('<script>x', $mail['html']);
        self::assertStringNotContainsString('<b>Board', $mail['html']);
        self::assertStringContainsString('&lt;script&gt;', $mail['html']);
    }

    public function test_date_range_spanning_two_months(): void
    {
        self::assertStringContainsString('28. September – 4. Oktober 2026', $this->render([$this->section('B')], 'de', '2026-09-28', '2026-10-04')['text']); // export-ok: comment-language
        self::assertStringContainsString('September 28 – October 4, 2026', $this->render([$this->section('B')], 'en', '2026-09-28', '2026-10-04')['text']);
    }

    public function test_copy_never_says_consensus(): void
    {
        foreach (['en', 'de'] as $lang) {
            $mail = $this->render([$this->section('B', ['awaiting_answer' => 1])], $lang);
            self::assertStringNotContainsStringIgnoringCase('consensus', $mail['text']);
            self::assertStringNotContainsStringIgnoringCase('konsens', $mail['text']);
        }
    }

    public function test_per_board_waiting_line_only_when_several_boards_wait(): void
    {
        $one = $this->render([$this->section('Solo', ['awaiting_answer' => 2]), $this->section('Other')])['text'];
        self::assertStringNotContainsString('for your answer, for more than 3 days', $one);

        $two = $this->render([$this->section('Alpha', ['awaiting_answer' => 2]), $this->section('Beta', ['awaiting_answer' => 1])])['text'];
        self::assertStringContainsString('On Alpha and Beta for more than 3 days', $two);
        self::assertStringContainsString('2 ideas are waiting for your answer, for more than 3 days.', $two);
        self::assertStringContainsString('1 idea is waiting for your answer, for more than 3 days.', $two);
    }
}
