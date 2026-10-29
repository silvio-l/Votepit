<?php

declare(strict_types=1);

namespace Votepit\Digest;

use Votepit\Mail\InlineImage;
use Votepit\Mail\MailBlock;
use Votepit\Mail\MailTemplate;

/**
 * Renders the weekly owner digest (de/en) through the shared MailTemplate.
 *
 * Layout (redesign 2026-10-26, reference: Codex variant A — "one clear task
 * first, then calm board sections"):
 *   1. header: logo, the week's date range on the right;
 *   2. heading = the one task ("2 ideas are waiting for your answer"), else
 *      a quiet "Your week on Votepit"; ONE button right below the lead;
 *   3. per board (boards waiting for an answer first, then by activity, then
 *      name): the linked board name; full boards (the first one and every
 *      board with waiting ideas) get figure tiles plus up to MAX_IDEAS idea
 *      rows, the others one figures line plus one idea row;
 *   4. footer: what the figures mean, why the mail arrives, two links.
 *
 * Ideas appear once per board (deduplicated by id) in the priority order
 * most voted → divided opinions → most down votes → newest. Up and down votes
 * are always shown separately; approval is the board's current per-idea
 * average and is left out when the board has no votes (never "0 %"). The
 * word "consensus" never appears in copy. The account name is only added
 * when two boards share a name.
 *
 * Idea/board/account names are tenant/user content — they only ever go
 * through MailTemplate, which HTML-escapes every interpolated value (the
 * plaintext part is plaintext by definition).
 *
 * @phpstan-type DigestIdea array{id: int, title: string, up_count?: int, down_count?: int}
 * @phpstan-type DigestSection array{
 *     account_name: string,
 *     board_name: string,
 *     board_url: string,
 *     admin_url: string,
 *     stats: array{weekly_votes: int, weekly_new_ideas: int, avg_consensus: int, total_ideas: int},
 *     highlights: array{new_ideas: list<DigestIdea>, top: list<DigestIdea>, most_rejected: list<DigestIdea>, most_polarising: list<DigestIdea>},
 *     awaiting_answer: int
 * }
 */
final class WeeklyDigestMail
{
    /** At most this many idea rows per (full) board section. */
    public const MAX_IDEAS = 3;

    private const TEXTS = [
        'en' => [
            'subject_waiting'  => 'Votepit: {waiting}',
            'subject_new'      => 'Your Votepit week: {new}',
            'subject_votes'    => 'Your Votepit week: {votes}',
            'subject_quiet'    => 'Your Votepit week: a quiet overview',
            'waiting'          => '{count} idea is waiting for your answer|{count} ideas are waiting for your answer',
            'heading'          => 'Your week on Votepit',
            'lead_waiting'     => 'On {boards} for more than 3 days without your answer.',
            'lead_hint'        => 'A comment or a status change is enough.',
            'lead_active'      => '{new} and {votes} on your boards this week.',
            'lead_quiet'       => 'A quiet week on your boards.',
            'new_ideas'        => '{count} new idea|{count} new ideas',
            'votes'            => '{count} vote|{count} votes',
            'tile_new'         => 'new ideas',
            'tile_votes'       => 'votes',
            'tile_week'        => 'this week',
            'tile_approval'    => 'approval',
            'tile_current'     => 'current state',
            'percent'          => '{value}%',
            'board_waiting'    => '{waiting}, for more than 3 days.',
            'compact'          => '{new} and {votes} this week',
            'compact_approval' => 'approval {approval}',
            'no_activity'      => 'No new ideas or votes this week.',
            'ideas_label'      => 'Ideas to watch',
            'kind_top'         => 'Most voted',
            'kind_divided'     => 'Divided opinions',
            'kind_down'        => 'Most down votes',
            'kind_new'         => 'New this week',
            'counts'           => '{up} for / {down} against',
            'and'              => ' and ',
            'cta_answer'       => 'Answer the ideas',
            'cta_idea'         => 'View the idea',
            'cta_board'        => 'View the board',
            'note'             => 'Weekly figures cover {range}. Approval and votes per idea show the current state; approval is the share of up votes.',
            'footer'           => 'You receive this weekly overview because you manage these boards on Votepit.',
            'settings'         => 'Mail settings',
            'unsubscribe'      => 'Unsubscribe from the weekly overview',
        ],
        'de' => [
            'subject_waiting'  => 'Votepit: {waiting}',
            'subject_new'      => 'Deine Votepit-Woche: {new}', // export-ok: comment-language
            'subject_votes'    => 'Deine Votepit-Woche: {votes}', // export-ok: comment-language
            'subject_quiet'    => 'Deine Votepit-Woche: ein ruhiger Überblick', // export-ok: comment-language
            'waiting'          => '{count} Idee wartet auf deine Antwort|{count} Ideen warten auf deine Antwort', // export-ok: comment-language
            'heading'          => 'Deine Woche auf Votepit', // export-ok: comment-language
            'lead_waiting'     => 'Auf {boards} seit mehr als 3 Tagen ohne deine Antwort.', // export-ok: comment-language
            'lead_hint'        => 'Ein Kommentar oder eine Statusänderung genügt.', // export-ok: comment-language
            'lead_active'      => '{new} und {votes} auf deinen Boards in dieser Woche.', // export-ok: comment-language
            'lead_quiet'       => 'Eine ruhige Woche auf deinen Boards.', // export-ok: comment-language
            'new_ideas'        => '{count} neue Idee|{count} neue Ideen', // export-ok: comment-language
            'votes'            => '{count} Stimme|{count} Stimmen', // export-ok: comment-language
            'tile_new'         => 'neue Ideen', // export-ok: comment-language
            'tile_votes'       => 'Stimmen', // export-ok: comment-language
            'tile_week'        => 'diese Woche', // export-ok: comment-language
            'tile_approval'    => 'Zustimmung', // export-ok: comment-language
            'tile_current'     => 'aktueller Stand', // export-ok: comment-language
            'percent'          => '{value} %',
            'board_waiting'    => '{waiting}, seit mehr als 3 Tagen.', // export-ok: comment-language
            'compact'          => '{new} und {votes} diese Woche', // export-ok: comment-language
            'compact_approval' => 'Zustimmung {approval}', // export-ok: comment-language
            'no_activity'      => 'Diese Woche keine neuen Ideen oder Stimmen.', // export-ok: comment-language
            'ideas_label'      => 'Ideen im Blick', // export-ok: comment-language
            'kind_top'         => 'Meistgewählt', // export-ok: comment-language
            'kind_divided'     => 'Geteilte Meinung', // export-ok: comment-language
            'kind_down'        => 'Meiste Gegenstimmen', // export-ok: comment-language
            'kind_new'         => 'Neu diese Woche', // export-ok: comment-language
            'counts'           => '{up} dafür / {down} dagegen', // export-ok: comment-language
            'and'              => ' und ', // export-ok: comment-language
            'cta_answer'       => 'Ideen beantworten', // export-ok: comment-language
            'cta_idea'         => 'Idee ansehen', // export-ok: comment-language
            'cta_board'        => 'Board ansehen', // export-ok: comment-language
            'note'             => 'Wochenzahlen gelten für den {range}. Zustimmung und Stimmen je Idee zeigen den aktuellen Stand; Zustimmung ist der Anteil positiver Stimmen.', // export-ok: comment-language
            'footer'           => 'Du erhältst diesen Wochenüberblick, weil du diese Boards auf Votepit verwaltest.', // export-ok: comment-language
            'settings'         => 'E-Mail-Einstellungen', // export-ok: comment-language
            'unsubscribe'      => 'Wochenüberblick abbestellen', // export-ok: comment-language
        ],
    ];

    private const MONTHS = [
        'en' => ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'],
        'de' => ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'], // export-ok: comment-language
    ];

    /**
     * @param list<DigestSection> $sections
     * @param \DateTimeImmutable $periodStart first day the weekly figures cover
     * @param \DateTimeImmutable $periodEnd   last day the weekly figures cover
     * @return array{subject: string, text: string, html: string, image: InlineImage}
     */
    public static function render(
        string $language,
        array $sections,
        string $ctaUrl,
        string $profileUrl,
        string $unsubscribeUrl,
        \DateTimeImmutable $periodStart,
        \DateTimeImmutable $periodEnd,
    ): array {
        $lang = isset(self::TEXTS[$language]) ? $language : 'en';
        $t    = self::TEXTS[$lang];

        $sections = self::ordered($sections);
        $awaiting = array_sum(array_column($sections, 'awaiting_answer'));
        $newIdeas = array_sum(array_map(static fn (array $s): int => $s['stats']['weekly_new_ideas'], $sections));
        $votes    = array_sum(array_map(static fn (array $s): int => $s['stats']['weekly_votes'], $sections));
        $names    = self::displayNames($sections);

        // 1. The one task, then the single button.
        $waitingNames = [];
        if ($awaiting > 0) {
            foreach ($sections as $i => $section) {
                if ($section['awaiting_answer'] > 0) {
                    $waitingNames[] = $names[$i];
                }
            }
            $heading    = self::plural($t['waiting'], $awaiting);
            $paragraphs = [strtr($t['lead_waiting'], ['{boards}' => self::joinNames($t, $waitingNames)]), MailBlock::meta($t['lead_hint'])];
        } else {
            $heading    = $t['heading'];
            $paragraphs = [$newIdeas + $votes > 0
                ? strtr($t['lead_active'], ['{new}' => self::plural($t['new_ideas'], $newIdeas), '{votes}' => self::plural($t['votes'], $votes)])
                : $t['lead_quiet']];
        }
        $paragraphs[] = MailBlock::cta();

        // 2. Calm board sections.
        foreach ($sections as $i => $section) {
            $full = $i === 0 || $section['awaiting_answer'] > 0;
            array_push($paragraphs, ...self::boardBlocks($t, $section, $names[$i], $full, count($waitingNames) > 1));
        }

        // A single board falls back to its own URL; several boards fall back to the app root.
        [$ctaLabel, $cta] = self::nextStep($t, $sections, count($sections) === 1 ? $sections[0]['board_url'] : $ctaUrl);

        $subject = match (true) {
            $awaiting > 0 => strtr($t['subject_waiting'], ['{waiting}' => self::plural($t['waiting'], $awaiting)]),
            $newIdeas > 0 => strtr($t['subject_new'], ['{new}' => self::plural($t['new_ideas'], $newIdeas)]),
            $votes > 0    => strtr($t['subject_votes'], ['{votes}' => self::plural($t['votes'], $votes)]),
            default       => $t['subject_quiet'],
        };

        $mail = MailTemplate::render(
            $heading,
            $paragraphs,
            $cta,
            $ctaLabel,
            [strtr($t['note'], ['{range}' => self::range($lang, $periodStart, $periodEnd, false)]), $t['footer']],
            ['label' => $t['unsubscribe'], 'url' => $unsubscribeUrl],
            headerNote: self::range($lang, $periodStart, $periodEnd, true),
            footerLinks: [['label' => $t['settings'], 'url' => $profileUrl]],
        );

        return ['subject' => $subject, ...$mail];
    }

    /**
     * Boards waiting for an answer first, then the busier ones, then by name.
     *
     * @param list<DigestSection> $sections
     * @return list<DigestSection>
     */
    private static function ordered(array $sections): array
    {
        usort(
            $sections,
            static fn (array $a, array $b): int => [$b['awaiting_answer'], $b['stats']['weekly_votes'] + $b['stats']['weekly_new_ideas'], $a['board_name']]
                <=> [$a['awaiting_answer'], $a['stats']['weekly_votes'] + $a['stats']['weekly_new_ideas'], $b['board_name']],
        );

        return $sections;
    }

    /**
     * Board names, with the account name added only where two boards share a name.
     *
     * @param list<DigestSection> $sections
     * @return list<string>
     */
    private static function displayNames(array $sections): array
    {
        $counts = array_count_values(array_column($sections, 'board_name'));

        return array_map(
            static fn (array $s): string => $counts[$s['board_name']] > 1 ? $s['board_name'] . ' (' . $s['account_name'] . ')' : $s['board_name'],
            $sections,
        );
    }

    /**
     * @param array<string, string> $t
     * @param DigestSection $section
     * @return list<MailBlock>
     */
    private static function boardBlocks(array $t, array $section, string $name, bool $full, bool $severalWaiting): array
    {
        $stats = $section['stats'];
        $new   = $stats['weekly_new_ideas'];
        $votes = $stats['weekly_votes'];
        // avg_consensus is 0 for a board without votes — never show that as "0 %".
        $approval = $stats['avg_consensus'] > 0 || $votes > 0
            ? strtr($t['percent'], ['{value}' => (string) $stats['avg_consensus']])
            : null;

        $blocks = [MailBlock::subheading($name, $section['board_url'])];

        if ($full) {
            $tiles = [
                ['value' => (string) $new, 'label' => $t['tile_new'], 'sub' => $t['tile_week']],
                ['value' => (string) $votes, 'label' => $t['tile_votes'], 'sub' => $t['tile_week']],
            ];
            if ($approval !== null) {
                $tiles[] = ['value' => $approval, 'label' => $t['tile_approval'], 'sub' => $t['tile_current']];
            }
            $blocks[] = MailBlock::stats($tiles, implode(' · ', array_map(static fn (array $tile): string => $tile['value'] . ' ' . $tile['label'], $tiles)));
            // With one waiting board the heading already says it.
            if ($severalWaiting && $section['awaiting_answer'] > 0) {
                $blocks[] = MailBlock::meta(strtr($t['board_waiting'], ['{waiting}' => self::plural($t['waiting'], $section['awaiting_answer'])]));
            }
        } elseif ($new + $votes === 0) {
            $blocks[] = MailBlock::meta($t['no_activity']);
        } else {
            $line = strtr($t['compact'], ['{new}' => self::plural($t['new_ideas'], $new), '{votes}' => self::plural($t['votes'], $votes)]);
            if ($approval !== null) {
                $line .= ' · ' . strtr($t['compact_approval'], ['{approval}' => $approval]);
            }
            $blocks[] = MailBlock::meta($line);
        }

        $ideas = self::ideaRows($t, $section, $full ? self::MAX_IDEAS : 1);
        if ($ideas !== [] && $full) {
            $blocks[] = MailBlock::label($t['ideas_label']);
        }

        return [...$blocks, ...$ideas];
    }

    /**
     * Up to $limit idea rows, each idea once and each highlight kind once:
     * most voted, divided opinions, most down votes; the newest ideas fill
     * the remaining rows.
     *
     * @param array<string, string> $t
     * @param DigestSection $section
     * @return list<MailBlock>
     */
    private static function ideaRows(array $t, array $section, int $limit): array
    {
        $h          = $section['highlights'];
        $candidates = [];
        foreach (['top' => 'kind_top', 'most_polarising' => 'kind_divided', 'most_rejected' => 'kind_down'] as $key => $kind) {
            foreach ($h[$key] as $idea) {
                $candidates[] = [$idea, $kind];
            }
        }
        foreach ($h['new_ideas'] as $idea) {
            $candidates[] = [$idea, 'kind_new'];
        }

        $rows      = [];
        $seenIdeas = [];
        $seenKinds = [];
        foreach ($candidates as [$idea, $kind]) {
            if (count($rows) >= $limit) {
                break;
            }
            if (isset($seenIdeas[$idea['id']]) || ($kind !== 'kind_new' && isset($seenKinds[$kind]))) {
                continue;
            }
            $seenIdeas[$idea['id']] = true;
            $seenKinds[$kind]       = true;

            $meta = isset($idea['up_count'])
                ? $t[$kind] . ' · ' . strtr($t['counts'], ['{up}' => (string) $idea['up_count'], '{down}' => (string) ($idea['down_count'] ?? 0)])
                : $t[$kind];
            $rows[] = MailBlock::idea($idea['title'], $meta, $section['board_url'] . '/idea/' . $idea['id']);
        }

        return $rows;
    }

    /**
     * @param array<string, string> $t
     * @param list<DigestSection> $sections ordered, see ordered()
     * @return array{0: string, 1: string} CTA label, CTA URL
     */
    private static function nextStep(array $t, array $sections, string $boardCtaUrl): array
    {
        // 1. Ideas waiting for an answer: the board with the most of them comes first.
        if (isset($sections[0]) && $sections[0]['awaiting_answer'] > 0) {
            return [$t['cta_answer'], $sections[0]['admin_url']];
        }

        // 2. The most voted idea of the week (approval minus rejection).
        $top = null;
        $topBoardUrl = null;
        foreach ($sections as $section) {
            $candidate = $section['highlights']['top'][0] ?? null;
            if ($candidate !== null && ($top === null || ($candidate['up_count'] ?? 0) - ($candidate['down_count'] ?? 0) > ($top['up_count'] ?? 0) - ($top['down_count'] ?? 0))) {
                $top = $candidate;
                $topBoardUrl = $section['board_url'];
            }
        }
        if ($top !== null && $topBoardUrl !== null) {
            return [$t['cta_idea'], $topBoardUrl . '/idea/' . $top['id']];
        }

        // 3. Otherwise the board itself.
        return [$t['cta_board'], $boardCtaUrl];
    }

    /**
     * "19.–25. Oktober 2026" / "October 19–25, 2026"; across months both
     * months are named. The footer note drops the year.
     */
    private static function range(string $lang, \DateTimeImmutable $start, \DateTimeImmutable $end, bool $withYear): string
    {
        $months    = self::MONTHS[$lang];
        $startMon  = $months[(int) $start->format('n') - 1];
        $endMon    = $months[(int) $end->format('n') - 1];
        $sameMonth = $start->format('Y-m') === $end->format('Y-m');

        if ($lang === 'de') {
            $from = $start->format('j') . '.' . ($sameMonth ? '–' : ' ' . $startMon . ' – ');
            return $from . $end->format('j') . '. ' . $endMon . ($withYear ? ' ' . $end->format('Y') : '');
        }

        $to = $sameMonth ? '–' . $end->format('j') : ' – ' . $endMon . ' ' . $end->format('j');

        return $startMon . ' ' . $start->format('j') . $to . ($withYear ? ', ' . $end->format('Y') : '');
    }

    /**
     * "A", "A and B", "A, B and C".
     *
     * @param array<string, string> $t
     * @param list<string> $names
     */
    private static function joinNames(array $t, array $names): string
    {
        $last = (string) array_pop($names);

        return $names === [] ? $last : implode(', ', $names) . $t['and'] . $last;
    }

    /**
     * Picks the singular or plural form of a "singular|plural" template.
     */
    private static function plural(string $template, int $count): string
    {
        $forms = explode('|', $template, 2);
        $form  = $count === 1 || !isset($forms[1]) ? $forms[0] : $forms[1];

        return strtr($form, ['{count}' => (string) $count]);
    }
}
