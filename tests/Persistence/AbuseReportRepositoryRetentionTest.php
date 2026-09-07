<?php

declare(strict_types=1);

namespace Votepit\Tests\Persistence;

use Votepit\Persistence\AbuseReportRepository;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * AbuseReportRepository::purgeReviewedBefore() (deep-review-2026-09 finding
 * g): reporter_email_enc is personal data with no prior retention limit.
 */
final class AbuseReportRepositoryRetentionTest extends IntegrationTestCase
{
    private function insertReport(string $status, ?string $reviewedAt): int
    {
        $repo = new AbuseReportRepository($this->conn);
        $id   = $repo->create('https://example.test/idea/1', 'spam', null, null, null, 'ciphertext');

        $this->conn->executeStatement(
            'UPDATE abuse_reports SET status = :status, reviewed_at = :reviewed_at WHERE id = :id',
            ['status' => $status, 'reviewed_at' => $reviewedAt, 'id' => $id],
        );

        return $id;
    }

    public function test_open_reports_are_never_purged_regardless_of_age(): void
    {
        $repo = new AbuseReportRepository($this->conn);
        $id   = $this->insertReport('open', null);

        $deleted = $repo->purgeReviewedBefore(new \DateTimeImmutable('+1 year'));

        self::assertSame(0, $deleted);
        self::assertNotNull($repo->findById($id));
    }

    public function test_reviewed_reports_past_the_cutoff_are_purged(): void
    {
        $repo = new AbuseReportRepository($this->conn);
        $id   = $this->insertReport('reviewed', (new \DateTimeImmutable('-200 days'))->format('Y-m-d H:i:s'));

        $deleted = $repo->purgeReviewedBefore(new \DateTimeImmutable('-180 days'));

        self::assertSame(1, $deleted);
        self::assertNull($repo->findById($id));
    }

    public function test_dismissed_reports_past_the_cutoff_are_purged(): void
    {
        $repo = new AbuseReportRepository($this->conn);
        $id   = $this->insertReport('dismissed', (new \DateTimeImmutable('-200 days'))->format('Y-m-d H:i:s'));

        $deleted = $repo->purgeReviewedBefore(new \DateTimeImmutable('-180 days'));

        self::assertSame(1, $deleted);
        self::assertNull($repo->findById($id));
    }

    public function test_recently_reviewed_reports_are_kept(): void
    {
        $repo = new AbuseReportRepository($this->conn);
        $id   = $this->insertReport('reviewed', (new \DateTimeImmutable('-10 days'))->format('Y-m-d H:i:s'));

        $deleted = $repo->purgeReviewedBefore(new \DateTimeImmutable('-180 days'));

        self::assertSame(0, $deleted);
        self::assertNotNull($repo->findById($id));
    }
}
