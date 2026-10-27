<?php

declare(strict_types=1);

namespace Votepit\Tests\Domain;

use Votepit\Domain\DuplicateCandidateFinder;
use Votepit\Domain\DuplicateDetectionService;
use Votepit\Domain\JaroWinklerSimilarity;
use Votepit\Domain\TitleNormalizer;
use Votepit\Extension\DuplicateConfirmationAvailability;
use Votepit\Extension\DuplicateConfirmer;
use Votepit\Persistence\IdeaRepository;
use Votepit\Tests\Support\IntegrationTestCase;
use Votepit\Tests\Support\RecordingDuplicateConfirmer;

/**
 * DuplicateCandidateFinder — the single deterministic candidate seam
 * (FULLTEXT/recency recall + Jaro–Winkler rerank) behind both the submit-
 * time duplicate hint and the moderator's merge suggestions (issue 10);
 * issue 11 layers the optional AI confirmation on top of it.
 */
final class DuplicateCandidateFinderTest extends IntegrationTestCase
{
    private DuplicateCandidateFinder $sut;

    protected function setUp(): void
    {
        parent::setUp();
        $normalizer = new TitleNormalizer();
        $this->sut  = new DuplicateCandidateFinder(
            new IdeaRepository($this->conn),
            new DuplicateDetectionService($normalizer, new JaroWinklerSimilarity()),
        );
    }

    public function test_finds_similar_ideas_of_the_board_ranked_by_similarity(): void
    {
        $boardId = $this->insertBoard('finder');
        $other   = $this->insertBoard('finder-other');
        $author  = $this->insertUser();
        $close   = $this->seedIdea($boardId, $author, 'Dark mode for the dashboard');
        $this->seedIdea($boardId, $author, 'Export to CSV');
        $this->seedIdea($other, $author, 'Dark mode for the dashboard');

        $ids = array_map(static fn (array $r): int => (int) $r['id'], $this->sut->find($boardId, 'Dark mode for dashboard'));

        self::assertSame([$close], $ids);
    }

    public function test_excludes_the_given_idea_and_merged_or_moderated_ideas(): void
    {
        $boardId = $this->insertBoard('finder-excl');
        $author  = $this->insertUser();
        $self    = $this->seedIdea($boardId, $author, 'Dark mode');
        $target  = $this->seedIdea($boardId, $author, 'Dark mode please');
        $this->seedIdea($boardId, $author, 'Dark mode!', ['merged_into_id' => $target]);
        $this->seedIdea($boardId, $author, 'Dark mode now', ['deleted_at' => '2026-01-01 00:00:00']);

        $ids = array_map(static fn (array $r): int => (int) $r['id'], $this->sut->find($boardId, 'Dark mode', excludeIdeaId: $self));

        self::assertSame([$target], $ids);
    }

    // --- Optional confirmation step (issue 11) -------------------------------

    private function finderWith(DuplicateConfirmer $confirmer): DuplicateCandidateFinder
    {
        return new DuplicateCandidateFinder(
            new IdeaRepository($this->conn),
            new DuplicateDetectionService(new TitleNormalizer(), new JaroWinklerSimilarity()),
            $confirmer,
        );
    }

    /** @return array{0: int, 1: int, 2: int, 3: int} board, rephrased duplicate, unrelated idea, author */
    private function rephrased(string $slug): array
    {
        $boardId   = $this->insertBoard($slug);
        $author    = $this->insertUser();
        $related   = $this->seedIdea($boardId, $author, 'Smartphone app for voting');
        $unrelated = $this->seedIdea($boardId, $author, 'Export to CSV');

        return [$boardId, $related, $unrelated, $author];
    }

    public function test_without_a_confirmer_rows_carry_no_verdict(): void
    {
        [$boardId, , , $author] = $this->rephrased('finder-none');
        $this->seedIdea($boardId, $author, 'Dark mode for the dashboard');

        $rows = $this->sut->find($boardId, 'Dark mode for the dashboard', confirmForAccountId: 1);

        self::assertNotSame([], $rows);
        self::assertSame([null], array_unique(array_column($rows, 'confirmed')));
        self::assertNull($this->sut->confirmationAvailability(1));
    }

    public function test_deterministic_matches_never_reach_the_confirmer(): void
    {
        [$boardId, , , $author] = $this->rephrased('finder-deterministic');
        $match     = $this->seedIdea($boardId, $author, 'Dark mode for the dashboard');
        $confirmer = new RecordingDuplicateConfirmer([]);

        $rows = $this->finderWith($confirmer)->find($boardId, 'Dark mode for the dashboard', confirmForAccountId: 7);

        self::assertSame([$match], array_map(static fn (array $r): int => (int) $r['id'], $rows));
        self::assertSame([null], array_column($rows, 'confirmed'));
        self::assertSame([], $confirmer->calls, 'A deterministic hit costs no AI call.');
    }

    public function test_without_deterministic_matches_the_confirmer_judges_the_near_misses(): void
    {
        [$boardId, $related] = $this->rephrased('finder-near');
        $confirmer = new RecordingDuplicateConfirmer([$related]);

        $rows = $this->finderWith($confirmer)->find($boardId, 'Offline mode for the app', confirmForAccountId: 7);

        self::assertSame([$related], array_map(static fn (array $r): int => (int) $r['id'], $rows));
        self::assertSame([true], array_column($rows, 'confirmed'));
        self::assertCount(1, $confirmer->calls);
        self::assertSame(7, $confirmer->calls[0]['account']);
        self::assertSame(
            [['id' => $related, 'title' => 'Smartphone app for voting']],
            $confirmer->calls[0]['candidates'],
            'Only id and title of the related near misses are handed over — unrelated ideas never.',
        );
    }

    public function test_only_confirmed_near_misses_are_shown_and_foreign_ids_are_discarded(): void
    {
        [$boardId] = $this->rephrased('finder-foreign');

        foreach ([[], [999_999], null] as $verdict) {
            $rows = $this->finderWith(new RecordingDuplicateConfirmer($verdict))
                ->find($boardId, 'Offline mode for the app', confirmForAccountId: 7);
            self::assertSame([], $rows);
        }
    }

    public function test_confirmer_is_not_asked_without_an_account_or_without_near_misses(): void
    {
        [$boardId] = $this->rephrased('finder-skip');
        $confirmer = new RecordingDuplicateConfirmer([]);
        $finder    = $this->finderWith($confirmer);

        $finder->find($boardId, 'Offline mode for the app');
        $finder->find($boardId, 'Completely unrelated words', confirmForAccountId: 7);

        self::assertSame([], $confirmer->calls);
    }

    public function test_availability_hides_a_switched_off_confirmer(): void
    {
        self::assertSame(
            DuplicateConfirmationAvailability::UpgradeRequired,
            $this->finderWith(new RecordingDuplicateConfirmer([], DuplicateConfirmationAvailability::UpgradeRequired))->confirmationAvailability(1),
        );
        self::assertNull($this->finderWith(new RecordingDuplicateConfirmer([], DuplicateConfirmationAvailability::Off))->confirmationAvailability(1));
    }
}
