<?php

declare(strict_types=1);

namespace Votepit\Dev;

use Doctrine\DBAL\Connection;
use Votepit\Config;
use Votepit\Domain\Premoderation;
use Votepit\Domain\TitleNormalizer;
use Votepit\Persistence\AccountMemberRepository;
use Votepit\Persistence\AccountRepository;
use Votepit\Persistence\BoardRepository;
use Votepit\Persistence\CommentRepository;
use Votepit\Persistence\IdeaRepository;
use Votepit\Persistence\TagRepository;
use Votepit\Persistence\UserRepository;
use Votepit\Persistence\VoteRepository;
use Votepit\Security\IdentityHasher;

/**
 * Dev-only demo content for the default (self-host) account: two public
 * boards with fake users, ideas across all statuses, up/down votes (incl.
 * zero-vote and low-participation cases), comments, tags, roadmap dates and
 * one pending idea for the moderation queue.
 *
 * Everything goes through the repositories, so the same invariants as the
 * HTTP actions apply (normalised titles, one vote per user and idea,
 * denormalised counters). The run is one transaction: it either completes or
 * leaves the database untouched. It is a no-op once the demo board exists,
 * and it refuses to construct at all unless DevSeedGuard accepts the config.
 */
final class DemoSeeder
{
    public const PRODUCT_BOARD_SLUG = 'demo-product';
    public const CLUB_BOARD_SLUG = 'demo-club';
    public const OWNER_EMAIL = 'demo-owner@votepit-dev.invalid';

    private const MIN_PASSWORD_LENGTH = 16;

    /**
     * Fake people. The owner gets the password from config/dev-seed.env; the
     * others cannot sign in with a password (no hash is set).
     *
     * @var array<string, array{username: string, role: ?string}>
     */
    private const PEOPLE = [
        'owner'  => ['username' => 'demo-owner', 'role' => 'owner'],
        'mona'   => ['username' => 'demo-mona', 'role' => 'moderator'],
        'anna'   => ['username' => 'demo-anna', 'role' => null],
        'ben'    => ['username' => 'demo-ben', 'role' => null],
        'clara'  => ['username' => 'demo-clara', 'role' => null],
        'daniel' => ['username' => 'demo-daniel', 'role' => null],
        'emma'   => ['username' => 'demo-emma', 'role' => null],
        'felix'  => ['username' => 'demo-felix', 'role' => null],
        'greta'  => ['username' => 'demo-greta', 'role' => null],
        'hannes' => ['username' => 'demo-hannes', 'role' => null],
        'ida'    => ['username' => 'demo-ida', 'role' => null],
    ];

    /**
     * Product feedback board. Votes: user key => +1 / -1.
     * `start`/`target` (Y-m-d) make a planned/in_progress/done idea show on the roadmap.
     *
     * @var list<array{title: string, body: string, author: string, status: string, tags: list<string>, votes: array<string, int>, comments: list<array{0: string, 1: string}>, start?: string, target?: string, pinned?: bool}>
     */
    private const PRODUCT_IDEAS = [
        [
            'title' => 'Dark mode for the dashboard',
            'body' => 'A dark theme that follows the system setting. Long sessions in the evening are hard on the eyes.',
            'author' => 'anna', 'status' => 'open', 'tags' => ['UI'],
            'votes' => ['ben' => 1, 'clara' => 1, 'daniel' => 1, 'emma' => 1, 'felix' => 1, 'greta' => 1, 'hannes' => -1],
            'comments' => [['ida', 'This is my number one request.'], ['owner', 'On the list for the next design pass.']],
        ],
        [
            'title' => 'Keyboard shortcuts for common actions',
            'body' => 'Shortcuts for voting, moving between ideas and opening the search would speed up moderation a lot.',
            'author' => 'ben', 'status' => 'open', 'tags' => ['UI'],
            'votes' => ['anna' => 1, 'clara' => 1],
            'comments' => [['daniel', 'Please include the search field as well.']],
        ],
        [
            'title' => 'Export ideas as CSV',
            'body' => 'Download the current list, including votes and status, as a spreadsheet file.',
            'author' => 'owner', 'status' => 'planned', 'tags' => ['Integrations'],
            'votes' => ['anna' => 1, 'ben' => 1, 'clara' => 1, 'daniel' => 1, 'emma' => 1, 'felix' => 1, 'greta' => 1, 'hannes' => 1, 'ida' => 1],
            'comments' => [['emma', 'Would love to export filtered lists too.'], ['owner', 'Good point, filters come with the next iteration.'], ['mona', 'Added to the roadmap.']],
            'start' => '2026-11-01', 'target' => '2026-12-15', 'pinned' => true,
        ],
        [
            'title' => 'Slack notification for new ideas',
            'body' => 'Post a short message to a channel whenever a new idea is submitted.',
            'author' => 'daniel', 'status' => 'planned', 'tags' => ['Integrations'],
            'votes' => ['anna' => 1, 'ben' => 1, 'emma' => 1, 'felix' => 1, 'ida' => -1],
            'comments' => [],
            'target' => '2027-01-31',
        ],
        [
            'title' => 'Faster loading on boards with many ideas',
            'body' => 'Boards with a few hundred ideas take several seconds to show the first page.',
            'author' => 'mona', 'status' => 'in_progress', 'tags' => ['Performance'],
            'votes' => ['anna' => 1, 'ben' => 1, 'clara' => 1, 'daniel' => 1, 'emma' => 1, 'felix' => 1, 'greta' => -1, 'hannes' => -1],
            'comments' => [['clara', 'Happens mostly with 300+ ideas.']],
            'start' => '2026-10-15', 'target' => '2026-11-30',
        ],
        [
            'title' => 'Offline support in the mobile app',
            'body' => 'Read the board without a connection and send votes once the device is back online.',
            'author' => 'emma', 'status' => 'open', 'tags' => ['Mobile'],
            'votes' => [],
            'comments' => [],
        ],
        [
            'title' => 'Passkeys as a second factor',
            'body' => 'Sign in with a passkey in addition to the password and the authenticator app.',
            'author' => 'clara', 'status' => 'done', 'tags' => ['Security'],
            'votes' => ['anna' => 1, 'ben' => 1, 'daniel' => 1, 'emma' => 1, 'felix' => 1, 'greta' => 1, 'hannes' => 1, 'ida' => 1],
            'comments' => [['anna', 'Works well on my phone.'], ['felix', 'Thanks for shipping this.']],
            'start' => '2026-08-01', 'target' => '2026-09-30',
        ],
        [
            'title' => 'Clearer API examples in the docs',
            'body' => 'Copy-paste examples for every endpoint, including error responses.',
            'author' => 'owner', 'status' => 'done', 'tags' => ['Docs'],
            'votes' => ['ben' => 1, 'daniel' => 1, 'greta' => -1],
            'comments' => [],
            'start' => '2026-07-01', 'target' => '2026-08-31',
        ],
        [
            'title' => 'Translate ideas automatically into my language',
            'body' => 'Show every idea in the reader\'s language without clicking a button.',
            'author' => 'felix', 'status' => 'declined', 'tags' => [],
            'votes' => ['ida' => -1],
            'comments' => [['mona', 'Declined: the on-demand translation covers this without translating every idea.']],
        ],
        [
            'title' => 'Custom board themes via CSS',
            'body' => 'Let each board load its own stylesheet.',
            'author' => 'greta', 'status' => 'archived', 'tags' => ['UI'],
            'votes' => ['clara' => 1, 'daniel' => 1, 'emma' => -1],
            'comments' => [],
        ],
        [
            'title' => 'Pin a status update to the top of the board',
            'body' => 'A short announcement above the idea list, for release notes or maintenance windows.',
            'author' => 'hannes', 'status' => 'open', 'tags' => ['UI'],
            'votes' => ['felix' => 1],
            'comments' => [],
        ],
    ];

    /** @var list<array{title: string, body: string, author: string, status: string, tags: list<string>, votes: array<string, int>, comments: list<array{0: string, 1: string}>, start?: string, target?: string, pinned?: bool}> */
    private const CLUB_IDEAS = [
        [
            'title' => 'Live music stage at the summer festival',
            'body' => 'A small stage with local bands in the afternoon.',
            'author' => 'clara', 'status' => 'open', 'tags' => ['Music'],
            'votes' => ['anna' => 1, 'ben' => 1, 'daniel' => 1, 'emma' => 1, 'felix' => 1],
            'comments' => [['greta', 'A local band would fit perfectly.']],
        ],
        [
            'title' => 'Vegan food stand',
            'body' => 'One stand with vegan dishes next to the regular food court.',
            'author' => 'daniel', 'status' => 'planned', 'tags' => ['Food'],
            'votes' => ['anna' => 1, 'ben' => 1, 'greta' => 1, 'hannes' => -1],
            'comments' => [['ida', 'Please, yes!']],
            'target' => '2027-06-01',
        ],
        [
            'title' => 'Shade area for kids',
            'body' => 'Tents or sun sails near the playground.',
            'author' => 'emma', 'status' => 'open', 'tags' => ['Family'],
            'votes' => [],
            'comments' => [],
        ],
        [
            'title' => 'More bike parking near the entrance',
            'body' => 'The current racks fill up before noon.',
            'author' => 'owner', 'status' => 'done', 'tags' => ['Logistics'],
            'votes' => ['anna' => 1],
            'comments' => [],
            'start' => '2026-09-01', 'target' => '2026-09-30',
        ],
        [
            'title' => 'Earlier closing time on Sunday',
            'body' => 'Close at 18:00 on Sundays so volunteers can get home earlier.',
            'author' => 'felix', 'status' => 'open', 'tags' => [],
            'votes' => ['ben' => -1, 'clara' => -1],
            'comments' => [],
        ],
    ];

    /** @var array{title: string, body: string, author: string} */
    private const PENDING_IDEA = [
        'title' => 'Import ideas from a spreadsheet',
        'body' => 'Upload a CSV file so that existing feedback does not have to be typed in again.',
        'author' => 'ben',
    ];

    /** @var array<string, string> tag name => colour */
    private const PRODUCT_TAGS = [
        'UI' => '#6366f1', 'Integrations' => '#0ea5e9', 'Performance' => '#f59e0b',
        'Security' => '#ef4444', 'Docs' => '#10b981', 'Mobile' => '#8b5cf6',
    ];

    /** @var array<string, string> tag name => colour */
    private const CLUB_TAGS = [
        'Music' => '#ec4899', 'Food' => '#22c55e', 'Family' => '#3b82f6', 'Logistics' => '#64748b',
    ];

    private readonly IdentityHasher $hasher;
    private readonly UserRepository $users;
    private readonly AccountRepository $accounts;
    private readonly AccountMemberRepository $members;
    private readonly BoardRepository $boards;
    private readonly IdeaRepository $ideas;
    private readonly VoteRepository $votes;
    private readonly CommentRepository $comments;
    private readonly TagRepository $tags;
    private readonly TitleNormalizer $titles;

    /** @var array<string, int> person key => user id */
    private array $userIds = [];

    /** @throws DevSeedRefusedException when the config is not a local dev installation */
    public function __construct(Config $config, private readonly Connection $conn)
    {
        DevSeedGuard::assertLocalDevelopment($config);

        $this->hasher   = new IdentityHasher($config->identityServerKey);
        $this->users    = new UserRepository($conn);
        $this->accounts = new AccountRepository($conn);
        $this->members  = new AccountMemberRepository($conn);
        $this->boards   = new BoardRepository($conn);
        $this->ideas    = new IdeaRepository($conn);
        $this->votes    = new VoteRepository($conn);
        $this->comments = new CommentRepository($conn);
        $this->tags     = new TagRepository($conn);
        $this->titles   = new TitleNormalizer();
    }

    /**
     * @return list<string> human-readable summary lines (never contains the password)
     * @throws \Doctrine\DBAL\Exception
     */
    public function seed(string $ownerPassword): array
    {
        if (strlen($ownerPassword) < self::MIN_PASSWORD_LENGTH) {
            throw new \InvalidArgumentException('owner password for the demo seed is missing or too short.');
        }

        $accountId = $this->accounts->defaultAccountId();

        $existing = $this->boards->findBySlugForAccount(self::PRODUCT_BOARD_SLUG, $accountId);
        if ($existing !== null) {
            // Top-up only: adds the G-09 bulk ideas that are still missing, never touches the rest.
            $added = $this->conn->transactional(
                fn (): int => $this->topUpBulkIdeas((int) $existing['id']),
            );
            if ($added === 0) {
                return ['Demo data already present (board "' . self::PRODUCT_BOARD_SLUG . '"): nothing changed. Run bin/dev-setup.sh --reset to rebuild.'];
            }

            return ['Demo data present: added ' . $added . ' bulk ideas to board "' . self::PRODUCT_BOARD_SLUG . '".'];
        }

        /** @var list<string> $summary */
        $summary = $this->conn->transactional(
            fn (): array => $this->seedInTransaction($accountId, $ownerPassword),
        );

        return $summary;
    }

    /** @return list<string> */
    private function seedInTransaction(int $accountId, string $ownerPassword): array
    {
        $this->accounts->updateLegalOperatorIdentity(
            $accountId,
            'Demo Operator (local dev)',
            'Demo Street 1, 00000 Demo City',
            'operator@votepit-dev.invalid',
            null,
        );

        $this->seedPeople($accountId, $ownerPassword);

        $productId = $this->seedBoard(
            $accountId,
            self::PRODUCT_BOARD_SLUG,
            'Demo Product Feedback',
            'Fake demo board for local design work. Nothing here is real.',
            '#4f46e5',
            self::PRODUCT_TAGS,
            self::PRODUCT_IDEAS,
        );

        // G-09 board-at-scale data: 128 more ideas over all statuses (see DemoBulkIdeas).
        $this->topUpBulkIdeas($productId);

        $pendingUserId = $this->userId(self::PENDING_IDEA['author']);
        $this->ideas->create(
            $productId,
            $pendingUserId,
            self::PENDING_IDEA['title'],
            $this->titles->normalize(self::PENDING_IDEA['title']),
            self::PENDING_IDEA['body'],
            Premoderation::STATE_PENDING,
        );

        $clubId = $this->seedBoard(
            $accountId,
            self::CLUB_BOARD_SLUG,
            'Demo Club Events',
            'Fake demo board for a club or community event. Nothing here is real.',
            '#0f766e',
            self::CLUB_TAGS,
            self::CLUB_IDEAS,
        );

        return [
            'Seeded default account: boards ' . self::PRODUCT_BOARD_SLUG . ' (' . count(self::PRODUCT_IDEAS) . ' ideas + 1 pending) and '
                . self::CLUB_BOARD_SLUG . ' (' . count(self::CLUB_IDEAS) . ' ideas).',
            'Users: ' . count(self::PEOPLE) . ' fake accounts; owner ' . self::OWNER_EMAIL . ' (password in config/dev-seed.env, not printed).',
            'Board ids: product=' . $productId . ', club=' . $clubId . '.',
        ];
    }

    private function seedPeople(int $accountId, string $ownerPassword): void
    {
        foreach (self::PEOPLE as $key => $person) {
            $user = $this->users->create($this->hasher->hash($this->emailFor($key)));
            $id   = (int) $user['id'];

            $this->userIds[$key] = $id;
            $this->users->markVerified($id);
            $this->users->setUsername($id, $person['username']);
            $this->users->setProfileVisible($id, true);

            if ($key === 'owner') {
                $this->users->setPasswordHash($id, password_hash($ownerPassword, PASSWORD_ARGON2ID));
            }
            if ($person['role'] !== null) {
                $this->members->addMember($accountId, $id, $person['role']);
            }
        }
    }

    /**
     * @param array<string, string> $tagColours
     * @param list<array{title: string, body: string, author: string, status: string, tags: list<string>, votes: array<string, int>, comments: list<array{0: string, 1: string}>, start?: string, target?: string, pinned?: bool}> $ideas
     */
    private function seedBoard(int $accountId, string $slug, string $name, string $intro, string $primary, array $tagColours, array $ideas): int
    {
        $boardId = $this->boards->create($accountId, $slug, $name, 'public', false);
        if ($boardId === null) {
            throw new \RuntimeException('demo seed: could not create board "' . $slug . '" (slug already taken).');
        }
        $this->boards->updateBranding($boardId, $accountId, $primary, '#0f172a', null, $intro, false);

        /** @var array<string, int> $tagIds */
        $tagIds = [];
        foreach ($tagColours as $tagName => $colour) {
            $tagId = $this->tags->create($boardId, $tagName, $colour);
            if ($tagId === null) {
                throw new \RuntimeException('demo seed: duplicate tag "' . $tagName . '".');
            }
            $tagIds[$tagName] = $tagId;
        }

        foreach ($ideas as $idea) {
            $this->insertIdea($boardId, $idea, $tagIds);
        }

        return $boardId;
    }

    /**
     * Adds the missing DemoBulkIdeas to an existing demo board. Matches on the
     * exact title, so a second run inserts nothing.
     *
     * @return int number of ideas inserted
     * @throws \Doctrine\DBAL\Exception
     */
    private function topUpBulkIdeas(int $boardId): int
    {
        $this->loadUserIds();

        /** @var array<string, int> $tagIds */
        $tagIds = [];
        foreach ($this->tags->listByBoard($boardId) as $tag) {
            $tagIds[$tag['name']] = $tag['id'];
        }

        $present = array_flip($this->conn->fetchFirstColumn(
            'SELECT title FROM ideas WHERE board_id = :board_id',
            ['board_id' => $boardId],
        ));

        $added = 0;
        foreach (DemoBulkIdeas::plan() as $idea) {
            if (isset($present[$idea['title']])) {
                continue;
            }
            $this->insertIdea($boardId, $idea, $tagIds);
            ++$added;
        }

        return $added;
    }

    /** Re-reads the person ids of an existing demo account (the top-up path creates no users). */
    private function loadUserIds(): void
    {
        $rows = $this->conn->fetchAllKeyValue('SELECT username, id FROM users WHERE username LIKE :prefix', ['prefix' => 'demo-%']);
        foreach (self::PEOPLE as $key => $person) {
            if (isset($rows[$person['username']])) {
                $this->userIds[$key] = (int) $rows[$person['username']];
            }
        }
    }

    /**
     * @param array{title: string, body: string, author: string, status: string, tags: list<string>, votes: array<string, int>, comments: list<array{0: string, 1: string}>, start?: string, target?: string, pinned?: bool} $idea
     * @param array<string, int> $tagIds
     */
    private function insertIdea(int $boardId, array $idea, array $tagIds): void
    {
        $authorId = $this->userId($idea['author']);
        $ideaId   = $this->ideas->create(
            $boardId,
            $authorId,
            $idea['title'],
            $this->titles->normalize($idea['title']),
            $idea['body'],
        );

        if ($idea['status'] !== 'open') {
            $this->ideas->updateStatus($boardId, $ideaId, $idea['status']);
        }
        if (isset($idea['start']) || isset($idea['target'])) {
            $this->ideas->updateDates($boardId, $ideaId, $idea['start'] ?? null, $idea['target'] ?? null);
        }
        if (($idea['pinned'] ?? false) === true) {
            $this->ideas->setPinned($boardId, $ideaId, true);
        }
        foreach ($idea['tags'] as $tagName) {
            $this->tags->assignToIdea($ideaId, $tagIds[$tagName] ?? throw new \RuntimeException('demo seed: unknown tag "' . $tagName . '".'));
        }
        foreach ($idea['votes'] as $voter => $value) {
            $voterId = $this->userId($voter);
            if ($voterId === $authorId) {
                throw new \RuntimeException('demo seed: author "' . $idea['author'] . '" must not vote on their own idea.');
            }
            $this->votes->cast($boardId, $ideaId, $voterId, $value);
        }
        foreach ($idea['comments'] as [$commenter, $body]) {
            $this->comments->create($ideaId, $this->userId($commenter), $body);
        }
    }

    private function userId(string $key): int
    {
        return $this->userIds[$key] ?? throw new \RuntimeException('demo seed: unknown person "' . $key . '".');
    }

    private function emailFor(string $key): string
    {
        return $key === 'owner' ? self::OWNER_EMAIL : 'demo-' . $key . '@votepit-dev.invalid';
    }
}
