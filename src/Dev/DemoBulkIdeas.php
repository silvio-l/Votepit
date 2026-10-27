<?php

declare(strict_types=1);

namespace Votepit\Dev;

/**
 * DEV-ONLY bulk ideas for the board-at-scale check (G-09): 128 demo ideas on
 * demo-product, spread over all six statuses and the product tags.
 *
 * Pure data and arithmetic, no database access. Everything (title, status,
 * author, tags, votes) depends only on the index, so a top-up is repeatable
 * and tests can assert the exact distribution.
 */
final class DemoBulkIdeas
{
    /** Titles per status, in the order they are inserted. Counts are fixed by the reference round. */
    private const TITLES = [
        'open' => [
            'Dark mode for the mobile app', 'Keyboard shortcut for search', 'Bulk-edit idea status',
            'Email digest of new ideas', 'Weekly summary for admins', 'Custom colours per board',
            'Export results as PDF', 'Import votes from a survey tool', 'Comment threads with replies',
            'Mention teammates in comments', 'Markdown preview while writing', 'Attach screenshots to ideas',
            'Follow an idea for updates', 'Duplicate detection while typing', 'Anonymous voting option',
            'Undo the last vote', 'Show who voted on an idea', 'Sort by number of comments',
            'Filter ideas by author', 'Pin an idea to the top', 'Slack alerts for status changes',
            'Microsoft Teams integration', 'Discord notifications', 'Webhook on new idea',
            'Zapier connector', 'Public API for reading ideas', 'Read-only embed widget',
            'Custom domain for a board', 'Two-factor sign-in', 'Single sign-on with Google',
            'Passwordless sign-in improvements', 'Read-only role for moderators', 'Audit log of admin actions',
            'GDPR export of my data', 'Automatic translation of titles', 'Right-to-left language support',
            'Larger text option', 'Faster load on long boards', 'Keep filters after reload',
            'Remember the last sort order', 'Print-friendly results view', 'Calendar view of roadmap dates',
            'Release notes linked to ideas', 'Changelog entry when done', 'Tag colours in the legend',
            'Merge duplicate ideas', 'Split a long idea into two', 'Idea templates for bug reports',
            'Required fields for submissions', 'Spam filter with captcha', 'Moderation queue for new ideas',
            'Block a user from voting', 'Notify the author on status change', 'Email reply to a comment',
            'Offline mode for the app', 'Push notifications on the phone', 'Widget for the company intranet',
            'Invoices as PDF', 'Annual billing option', 'Vote on comments', 'Idea of the week highlight',
            'Shareable link per idea', 'Board-level feature flags', 'Idea of the month badge',
        ],
        'planned' => [
            'Native iOS app', 'Native Android app', 'Tag-based roadmap lanes', 'Custom email templates',
            'Monthly vote limit per user', 'Clone a board', 'Multi-language board intro',
            'Extra question on submissions', 'Score history chart', 'Search across comments',
            'Shareable filter links', 'CSV import with column mapping', 'Owner assignment for ideas',
            'Due dates on roadmap items', 'Sub-tasks on ideas', 'Batch moderation of comments',
            'Board analytics dashboard', 'Public changelog page', 'Idea categories with icons',
            'Team workspaces', 'Role-based board access', 'Reminder email to vote',
            'Editable board footer', 'Keyboard navigation in lists',
        ],
        'in_progress' => [
            'Faster search indexing', 'New comment editor', 'Reworked vote buttons', 'Mobile navigation bar',
            'Dark mode for the board', 'Improved onboarding checklist', 'Clearer error messages on submit',
            'Accessible focus styles', 'Board branding presets', 'Retry failed notifications',
            'Admin dashboard redesign', 'Export to Google Sheets',
        ],
        'done' => [
            'Vote buttons with counts', 'Status labels on ideas', 'Comment counts on the list',
            'Idea detail page', 'Tags on ideas', 'Board intro text', 'Sign-in by magic link',
            'Mobile-friendly board', 'Pinned ideas', 'Sorting by top and newest', 'Search by title',
            'Board logo upload', 'Email confirmation for accounts', 'Free plan limits',
            'Cookie consent banner', 'Privacy policy page', 'Imprint page', 'Idea edit history',
        ],
        'declined' => [
            'Cryptocurrency voting tokens', 'Built-in video conferencing', 'Paid votes',
            'Social sign-in with Facebook', 'Ad-supported free plan', 'Native Windows desktop app',
            'Public profiles with follower counts', 'Badges for voters',
        ],
        'archived' => [
            'Old Flash widget', 'Legacy import from Excel 2003',
        ],
    ];

    /** Product tags of demo-product (see DemoSeeder::PRODUCT_TAGS). */
    private const TAGS = ['UI', 'Integrations', 'Performance', 'Security', 'Docs', 'Mobile'];

    /** Voters are the demo people without the owner (the owner's own board stays untouched). */
    private const VOTERS = ['anna', 'ben', 'clara', 'daniel', 'emma', 'felix', 'greta', 'hannes', 'ida', 'mona'];

    /**
     * @return list<array{title: string, body: string, author: string, status: string, tags: list<string>, votes: array<string, int>, comments: list<array{0: string, 1: string}>}>
     */
    public static function plan(): array
    {
        $plan  = [];
        $index = 0;
        foreach (self::TITLES as $status => $titles) {
            foreach ($titles as $title) {
                $plan[] = self::idea($index, $title, $status);
                ++$index;
            }
        }

        return $plan;
    }

    /** @return array{title: string, body: string, author: string, status: string, tags: list<string>, votes: array<string, int>, comments: list<array{0: string, 1: string}>} */
    private static function idea(int $index, string $title, string $status): array
    {
        $count  = count(self::VOTERS);
        $author = self::VOTERS[$index % $count];

        // Up to six "for" and two "against" votes, never from the author, never more than one vote per voter.
        $up   = ($index * 5) % 7;
        $down = ($index * 2) % 3;
        $votes = [];
        $pos   = ($index + 3) % $count;
        for ($taken = 0; $taken < $up + $down;) {
            $voter = self::VOTERS[$pos % $count];
            ++$pos;
            if ($voter === $author) {
                continue;
            }
            $votes[$voter] = $taken < $up ? 1 : -1;
            ++$taken;
        }

        $tags = [self::TAGS[$index % count(self::TAGS)]];
        if ($index % 3 === 0) {
            $tags[] = self::TAGS[($index + 2) % count(self::TAGS)];
        }

        return [
            'title'    => $title,
            'body'     => 'Demo idea for the board-at-scale check. Nothing here is real.',
            'author'   => $author,
            'status'   => $status,
            'tags'     => array_values(array_unique($tags)),
            'votes'    => $votes,
            'comments' => [],
        ];
    }
}
