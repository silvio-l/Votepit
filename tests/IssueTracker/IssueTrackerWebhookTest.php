<?php

declare(strict_types=1);

namespace Votepit\Tests\IssueTracker;

use PHPUnit\Framework\TestCase;
use Votepit\IssueTracker\IssueTrackerProvider;
use Votepit\IssueTracker\IssueTrackerWebhook;

final class IssueTrackerWebhookTest extends TestCase
{
    public function test_github_signature_is_sha256_prefixed_hmac(): void
    {
        $body = '{"action":"closed"}';
        $sig  = 'sha256=' . hash_hmac('sha256', $body, 's3cret');

        self::assertTrue(IssueTrackerWebhook::signatureValid(IssueTrackerProvider::GitHub, 's3cret', $body, $sig));
        self::assertFalse(IssueTrackerWebhook::signatureValid(IssueTrackerProvider::GitHub, 'other', $body, $sig));
        self::assertFalse(IssueTrackerWebhook::signatureValid(IssueTrackerProvider::GitHub, 's3cret', $body . ' ', $sig));
        self::assertFalse(IssueTrackerWebhook::signatureValid(IssueTrackerProvider::GitHub, 's3cret', $body, hash_hmac('sha256', $body, 's3cret')));
        self::assertFalse(IssueTrackerWebhook::signatureValid(IssueTrackerProvider::GitHub, 's3cret', $body, ''));
        self::assertFalse(IssueTrackerWebhook::signatureValid(IssueTrackerProvider::GitHub, '', $body, 'sha256=' . hash_hmac('sha256', $body, '')));
    }

    public function test_linear_signature_is_plain_hex_hmac(): void
    {
        $body = '{"type":"Issue"}';
        $sig  = hash_hmac('sha256', $body, 'lin-secret');

        self::assertTrue(IssueTrackerWebhook::signatureValid(IssueTrackerProvider::Linear, 'lin-secret', $body, $sig));
        self::assertTrue(IssueTrackerWebhook::signatureValid(IssueTrackerProvider::Linear, 'lin-secret', $body, strtoupper($sig)));
        self::assertFalse(IssueTrackerWebhook::signatureValid(IssueTrackerProvider::Linear, 'lin-secret', $body, 'sha256=' . $sig));
    }

    public function test_linear_replay_window(): void
    {
        $now = 1_800_000_000_000;
        self::assertTrue(IssueTrackerWebhook::withinReplayWindow(IssueTrackerProvider::Linear, ['webhookTimestamp' => $now - 59_000], $now));
        self::assertFalse(IssueTrackerWebhook::withinReplayWindow(IssueTrackerProvider::Linear, ['webhookTimestamp' => $now - 61_000], $now));
        self::assertFalse(IssueTrackerWebhook::withinReplayWindow(IssueTrackerProvider::Linear, ['webhookTimestamp' => $now + 61_000], $now));
        self::assertFalse(IssueTrackerWebhook::withinReplayWindow(IssueTrackerProvider::Linear, [], $now));
        self::assertFalse(IssueTrackerWebhook::withinReplayWindow(IssueTrackerProvider::Linear, ['webhookTimestamp' => (string) $now], $now));
        // GitHub signs no timestamp — nothing to check.
        self::assertTrue(IssueTrackerWebhook::withinReplayWindow(IssueTrackerProvider::GitHub, [], $now));
    }

    public function test_linear_completed_issue_is_detected(): void
    {
        $payload = ['type' => 'Issue', 'action' => 'update', 'data' => ['id' => 'lin-uuid', 'state' => ['type' => 'completed']]];
        self::assertSame('lin-uuid', IssueTrackerWebhook::completedIssueId(IssueTrackerProvider::Linear, '', $payload, 'ENG'));

        $payload['data']['state']['type'] = 'canceled';
        self::assertNull(IssueTrackerWebhook::completedIssueId(IssueTrackerProvider::Linear, '', $payload, 'ENG'));

        $payload = ['type' => 'Comment', 'action' => 'update', 'data' => ['id' => 'x', 'state' => ['type' => 'completed']]];
        self::assertNull(IssueTrackerWebhook::completedIssueId(IssueTrackerProvider::Linear, '', $payload, 'ENG'));

        $payload = ['type' => 'Issue', 'action' => 'remove', 'data' => ['id' => 'x', 'state' => ['type' => 'completed']]];
        self::assertNull(IssueTrackerWebhook::completedIssueId(IssueTrackerProvider::Linear, '', $payload, 'ENG'));
    }

    public function test_github_closed_issue_of_the_connected_repo_is_detected(): void
    {
        $payload = ['action' => 'closed', 'issue' => ['id' => 987, 'state_reason' => 'completed'], 'repository' => ['full_name' => 'Acme/Roadmap']];
        self::assertSame('987', IssueTrackerWebhook::completedIssueId(IssueTrackerProvider::GitHub, 'issues', $payload, 'acme/roadmap'));
        self::assertNull(IssueTrackerWebhook::completedIssueId(IssueTrackerProvider::GitHub, 'issue_comment', $payload, 'acme/roadmap'));
        self::assertNull(IssueTrackerWebhook::completedIssueId(IssueTrackerProvider::GitHub, 'issues', $payload, 'acme/other'));

        $notPlanned = $payload;
        $notPlanned['issue']['state_reason'] = 'not_planned';
        self::assertNull(IssueTrackerWebhook::completedIssueId(IssueTrackerProvider::GitHub, 'issues', $notPlanned, 'acme/roadmap'));

        $reopened           = $payload;
        $reopened['action'] = 'reopened';
        self::assertNull(IssueTrackerWebhook::completedIssueId(IssueTrackerProvider::GitHub, 'issues', $reopened, 'acme/roadmap'));
    }
}
