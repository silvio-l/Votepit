<?php

declare(strict_types=1);

namespace Votepit\Tests\Persistence;

use Votepit\Persistence\VoteRepository;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Persistence seam for VoteRepository::cast — the pure transaction logic
 * (insert/switch/retract + score delta) isolated from the HTTP stack.
 *
 * Proves the load-bearing ADR-3 amendment invariant: score_cache is maintained
 * app-side within the same transaction and matches SUM(votes.value) after every sequence.
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

        self::assertSame(1, $this->rowCount($ideaId, $userId), 'Switch must not create a second row.');
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

        self::assertSame(0, $this->rowCount($ideaId, $userId), 'Repeating the same arrow deletes the row.');
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

    // -------------------------------------------------------------------
    // castAnonymous (ADR 0004)
    // -------------------------------------------------------------------

    private function tokenRowCount(int $ideaId, string $tokenHash): int
    {
        return (int) $this->conn->fetchOne(
            'SELECT COUNT(*) FROM votes WHERE idea_id = :idea AND voter_token_hash = :token',
            ['idea' => $ideaId, 'token' => $tokenHash],
        );
    }

    public function test_anonymous_first_vote_inserts_row_with_null_user_id(): void
    {
        $boardId = $this->insertBoard('vr-anon-insert');
        $author  = $this->insertUser('vr-anon-author@example.com');
        $ideaId  = $this->seedIdea($boardId, $author);

        $result = $this->repo->castAnonymous($boardId, $ideaId, 'tok-hash-1', 1);

        self::assertSame(1, $this->tokenRowCount($ideaId, 'tok-hash-1'));
        self::assertSame(1, $this->scoreCache($ideaId));
        self::assertSame('up', $result['my_vote']);
        $row = $this->conn->fetchAssociative('SELECT user_id, voter_token_hash FROM votes WHERE idea_id = :idea', ['idea' => $ideaId]);
        self::assertIsArray($row);
        self::assertNull($row['user_id']);
        self::assertSame('tok-hash-1', $row['voter_token_hash']);
    }

    public function test_anonymous_vote_toggle_switch_and_retract(): void
    {
        $boardId = $this->insertBoard('vr-anon-toggle');
        $author  = $this->insertUser('vr-anon-toggle-author@example.com');
        $ideaId  = $this->seedIdea($boardId, $author);

        $this->repo->castAnonymous($boardId, $ideaId, 'tok-hash-2', 1);
        $switched = $this->repo->castAnonymous($boardId, $ideaId, 'tok-hash-2', -1);
        self::assertSame(1, $this->tokenRowCount($ideaId, 'tok-hash-2'), 'Switch must not create a second row.');
        self::assertSame(-1, $switched['score']);

        $retracted = $this->repo->castAnonymous($boardId, $ideaId, 'tok-hash-2', -1);
        self::assertSame(0, $this->tokenRowCount($ideaId, 'tok-hash-2'), 'Repeating the same arrow deletes the row.');
        self::assertSame('none', $retracted['my_vote']);
        self::assertSame(0, $retracted['score']);
    }

    public function test_two_distinct_anonymous_tokens_both_count_on_same_idea(): void
    {
        $boardId = $this->insertBoard('vr-anon-two');
        $author  = $this->insertUser('vr-anon-two-author@example.com');
        $ideaId  = $this->seedIdea($boardId, $author);

        $this->repo->castAnonymous($boardId, $ideaId, 'tok-a', 1);
        $result = $this->repo->castAnonymous($boardId, $ideaId, 'tok-b', 1);

        self::assertSame(2, $result['up_count']);
        self::assertSame(2, $result['score']);
    }

    public function test_same_token_cannot_double_vote_due_to_unique_constraint(): void
    {
        $boardId = $this->insertBoard('vr-anon-unique');
        $author  = $this->insertUser('vr-anon-unique-author@example.com');
        $ideaId  = $this->seedIdea($boardId, $author);

        // A raw INSERT bypassing the repository's idempotency logic must
        // still be stopped by the UNIQUE(idea_id, voter_token_hash) DB
        // backstop (defense in depth, same reasoning as the authenticated
        // path's uq_votes_idea_user).
        $this->conn->insert('votes', [
            'idea_id'          => $ideaId,
            'voter_token_hash' => 'tok-dup',
            'value'            => 1,
            'created_at'       => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        $this->expectException(\Doctrine\DBAL\Exception::class);
        $this->conn->insert('votes', [
            'idea_id'          => $ideaId,
            'voter_token_hash' => 'tok-dup',
            'value'            => -1,
            'created_at'       => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    public function test_mixed_authenticated_and_anonymous_votes_aggregate_correctly(): void
    {
        $boardId = $this->insertBoard('vr-mixed');
        $author  = $this->insertUser('vr-mixed-author@example.com');
        $ideaId  = $this->seedIdea($boardId, $author);
        $userId  = $this->insertUser('vr-mixed-voter@example.com');

        $this->repo->cast($boardId, $ideaId, $userId, 1);
        $this->repo->castAnonymous($boardId, $ideaId, 'tok-mixed-1', 1);
        $result = $this->repo->castAnonymous($boardId, $ideaId, 'tok-mixed-2', -1);

        self::assertSame(2, $result['up_count']);
        self::assertSame(1, $result['down_count']);
        self::assertSame(1, $result['score']);
        self::assertSame($this->voteSum($ideaId), $this->scoreCache($ideaId));
    }

    public function test_anonymous_vote_stores_optional_email_enc(): void
    {
        $boardId = $this->insertBoard('vr-anon-email');
        $author  = $this->insertUser('vr-anon-email-author@example.com');
        $ideaId  = $this->seedIdea($boardId, $author);

        $this->repo->castAnonymous($boardId, $ideaId, 'tok-email', 1, 'v1:ciphertext-blob-abc');

        $row = $this->conn->fetchAssociative(
            'SELECT voter_email_enc FROM votes WHERE idea_id = :idea AND voter_token_hash = :token',
            ['idea' => $ideaId, 'token' => 'tok-email'],
        );
        self::assertIsArray($row);
        self::assertSame('v1:ciphertext-blob-abc', $row['voter_email_enc']);
    }

    public function test_anonymous_vote_without_email_stores_null(): void
    {
        $boardId = $this->insertBoard('vr-anon-no-email');
        $author  = $this->insertUser('vr-anon-no-email-author@example.com');
        $ideaId  = $this->seedIdea($boardId, $author);

        $this->repo->castAnonymous($boardId, $ideaId, 'tok-no-email', 1);

        $row = $this->conn->fetchAssociative(
            'SELECT voter_email_enc FROM votes WHERE idea_id = :idea AND voter_token_hash = :token',
            ['idea' => $ideaId, 'token' => 'tok-no-email'],
        );
        self::assertIsArray($row);
        self::assertNull($row['voter_email_enc']);
    }

    public function test_distinct_anonymous_voter_emails_enc_returns_only_non_null_ciphertexts(): void
    {
        $boardId = $this->insertBoard('vr-anon-emails-list');
        $author  = $this->insertUser('vr-anon-emails-list-author@example.com');
        $ideaId  = $this->seedIdea($boardId, $author);

        $this->repo->castAnonymous($boardId, $ideaId, 'tok-a', 1, 'v1:enc-a');
        $this->repo->castAnonymous($boardId, $ideaId, 'tok-b', -1, 'v1:enc-b');
        $this->repo->castAnonymous($boardId, $ideaId, 'tok-c', 1);

        $blobs = $this->repo->distinctAnonymousVoterEmailsEnc($ideaId);

        sort($blobs);
        self::assertSame(['v1:enc-a', 'v1:enc-b'], $blobs);
    }

    // --- updateAnonymousNotifyEmail (ADR 0004 UX follow-up) ------------------

    public function test_update_anonymous_notify_email_sets_it_on_an_existing_vote(): void
    {
        $boardId = $this->insertBoard('vr-anon-email-update');
        $author  = $this->insertUser('vr-anon-email-update-author@example.com');
        $ideaId  = $this->seedIdea($boardId, $author);
        $this->repo->castAnonymous($boardId, $ideaId, 'tok-update', 1);

        $updated = $this->repo->updateAnonymousNotifyEmail($ideaId, 'tok-update', 'v1:ciphertext-later');

        self::assertTrue($updated);
        $row = $this->conn->fetchAssociative(
            'SELECT voter_email_enc FROM votes WHERE idea_id = :idea AND voter_token_hash = :token',
            ['idea' => $ideaId, 'token' => 'tok-update'],
        );
        self::assertIsArray($row);
        self::assertSame('v1:ciphertext-later', $row['voter_email_enc']);
    }

    public function test_update_anonymous_notify_email_can_overwrite_and_clear(): void
    {
        $boardId = $this->insertBoard('vr-anon-email-overwrite');
        $author  = $this->insertUser('vr-anon-email-overwrite-author@example.com');
        $ideaId  = $this->seedIdea($boardId, $author);
        $this->repo->castAnonymous($boardId, $ideaId, 'tok-overwrite', 1, 'v1:enc-original');

        $this->repo->updateAnonymousNotifyEmail($ideaId, 'tok-overwrite', 'v1:enc-changed');
        $row = $this->conn->fetchAssociative(
            'SELECT voter_email_enc FROM votes WHERE idea_id = :idea AND voter_token_hash = :token',
            ['idea' => $ideaId, 'token' => 'tok-overwrite'],
        );
        self::assertSame('v1:enc-changed', $row['voter_email_enc'] ?? null);

        $this->repo->updateAnonymousNotifyEmail($ideaId, 'tok-overwrite', null);
        $row = $this->conn->fetchAssociative(
            'SELECT voter_email_enc FROM votes WHERE idea_id = :idea AND voter_token_hash = :token',
            ['idea' => $ideaId, 'token' => 'tok-overwrite'],
        );
        self::assertIsArray($row);
        self::assertNull($row['voter_email_enc']);
    }

    public function test_update_anonymous_notify_email_returns_false_when_no_vote_exists(): void
    {
        $boardId = $this->insertBoard('vr-anon-email-no-vote');
        $author  = $this->insertUser('vr-anon-email-no-vote-author@example.com');
        $ideaId  = $this->seedIdea($boardId, $author);

        $updated = $this->repo->updateAnonymousNotifyEmail($ideaId, 'tok-never-voted', 'v1:ciphertext');

        self::assertFalse($updated);
        self::assertSame(0, $this->tokenRowCount($ideaId, 'tok-never-voted'));
    }

    public function test_update_anonymous_notify_email_never_touches_another_voters_row(): void
    {
        $boardId = $this->insertBoard('vr-anon-email-isolation');
        $author  = $this->insertUser('vr-anon-email-isolation-author@example.com');
        $ideaId  = $this->seedIdea($boardId, $author);
        $this->repo->castAnonymous($boardId, $ideaId, 'tok-mine', 1);
        $this->repo->castAnonymous($boardId, $ideaId, 'tok-other', -1, 'v1:enc-other-original');

        $this->repo->updateAnonymousNotifyEmail($ideaId, 'tok-mine', 'v1:enc-mine');

        $other = $this->conn->fetchAssociative(
            'SELECT voter_email_enc FROM votes WHERE idea_id = :idea AND voter_token_hash = :token',
            ['idea' => $ideaId, 'token' => 'tok-other'],
        );
        self::assertSame('v1:enc-other-original', $other['voter_email_enc'] ?? null);
    }
}
