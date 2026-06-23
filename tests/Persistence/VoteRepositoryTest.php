<?php

declare(strict_types=1);

namespace Votepit\Tests\Persistence;

use Votepit\Persistence\VoteRepository;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Persistence-Naht für VoteRepository::cast — die reine Transaktionslogik
 * (Insert/Switch/Retract + Score-Delta) isoliert vom HTTP-Stack (Sprint 4, Issue 01).
 *
 * Beweist die tragende ADR-3-Amendment-Invariante: score_cache wird app-seitig in
 * derselben Transaktion gepflegt und gleicht nach jeder Sequenz SUM(votes.value).
 */
final class VoteRepositoryTest extends IntegrationTestCase
{
    private VoteRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new VoteRepository($this->conn);
    }

    private function scoreCache(int $ideaId): int
    {
        return (int) $this->conn->fetchOne('SELECT score_cache FROM ideas WHERE id = :id', ['id' => $ideaId]);
    }

    private function voteSum(int $ideaId): int
    {
        return (int) $this->conn->fetchOne('SELECT COALESCE(SUM(value), 0) FROM votes WHERE idea_id = :id', ['id' => $ideaId]);
    }

    private function rowCount(int $ideaId, int $userId): int
    {
        return (int) $this->conn->fetchOne(
            'SELECT COUNT(*) FROM votes WHERE idea_id = :idea AND user_id = :user',
            ['idea' => $ideaId, 'user' => $userId],
        );
    }

    public function test_first_vote_inserts_row_and_increments_score(): void
    {
        $boardId = $this->insertBoard('vr-insert');
        $userId  = $this->insertUser('vr-insert@example.com');
        $ideaId  = $this->seedIdea($boardId, $userId);

        $result = $this->repo->cast($boardId, $ideaId, $userId, 1);

        self::assertSame(1, $this->rowCount($ideaId, $userId));
        self::assertSame(1, $this->scoreCache($ideaId));
        self::assertSame('up', $result['my_vote']);
        self::assertSame(1, $result['score']);
    }

    public function test_switch_up_to_down_updates_in_place_no_second_row(): void
    {
        $boardId = $this->insertBoard('vr-switch');
        $userId  = $this->insertUser('vr-switch@example.com');
        $ideaId  = $this->seedIdea($boardId, $userId);

        $this->repo->cast($boardId, $ideaId, $userId, 1);
        $result = $this->repo->cast($boardId, $ideaId, $userId, -1);

        self::assertSame(1, $this->rowCount($ideaId, $userId), 'Switch darf keine zweite Zeile erzeugen.');
        self::assertSame(-1, $this->scoreCache($ideaId));
        self::assertSame('down', $result['my_vote']);
        self::assertSame(-1, $result['score']);
    }

    public function test_same_arrow_again_retracts_and_deletes_row(): void
    {
        $boardId = $this->insertBoard('vr-retract');
        $userId  = $this->insertUser('vr-retract@example.com');
        $ideaId  = $this->seedIdea($boardId, $userId);

        $this->repo->cast($boardId, $ideaId, $userId, 1);
        $result = $this->repo->cast($boardId, $ideaId, $userId, 1);

        self::assertSame(0, $this->rowCount($ideaId, $userId), 'Erneuter gleicher Pfeil löscht die Zeile.');
        self::assertSame(0, $this->scoreCache($ideaId));
        self::assertSame('none', $result['my_vote']);
        self::assertSame(0, $result['score']);
    }

    public function test_score_cache_equals_sum_after_multi_user_sequence(): void
    {
        $boardId = $this->insertBoard('vr-seq');
        $author  = $this->insertUser('vr-author@example.com');
        $ideaId  = $this->seedIdea($boardId, $author);

        $u1 = $this->insertUser('vr-u1@example.com');
        $u2 = $this->insertUser('vr-u2@example.com');
        $u3 = $this->insertUser('vr-u3@example.com');

        $this->repo->cast($boardId, $ideaId, $u1, 1);   // +1
        $this->repo->cast($boardId, $ideaId, $u2, 1);   // +1
        $this->repo->cast($boardId, $ideaId, $u3, -1);  // -1   → sum 1
        $this->repo->cast($boardId, $ideaId, $u2, -1);  // switch u2 up→down → sum -1
        $this->repo->cast($boardId, $ideaId, $u1, 1);   // retract u1 → sum -2

        self::assertSame($this->voteSum($ideaId), $this->scoreCache($ideaId));
        self::assertSame(-2, $this->scoreCache($ideaId));
    }

    public function test_find_user_vote_reflects_state(): void
    {
        $boardId = $this->insertBoard('vr-find');
        $userId  = $this->insertUser('vr-find@example.com');
        $ideaId  = $this->seedIdea($boardId, $userId);

        self::assertSame('none', $this->repo->findUserVote($ideaId, $userId));

        $this->repo->cast($boardId, $ideaId, $userId, 1);
        self::assertSame('up', $this->repo->findUserVote($ideaId, $userId));

        $this->repo->cast($boardId, $ideaId, $userId, -1);
        self::assertSame('down', $this->repo->findUserVote($ideaId, $userId));
    }
}
