<?php

declare(strict_types=1);

namespace Votepit\IssueTracker;

/**
 * Inbound status-sync webhooks from Linear and GitHub — signature check,
 * replay window and event interpretation (pure functions, no I/O).
 *
 *  - GitHub: `X-Hub-Signature-256: sha256=<hex HMAC-SHA256(raw body)>`.
 *    GitHub offers no signed timestamp, so there is no replay window; a
 *    replayed "closed" delivery is idempotent anyway (the idea is already
 *    done → no-op).
 *  - Linear: `Linear-Signature: <hex HMAC-SHA256(raw body)>`, plus the
 *    signed `webhookTimestamp` (ms) in the body, which must be within
 *    REPLAY_WINDOW_MS of now (Linear's documented recommendation: 1 min).
 *
 * Comparisons are constant-time (hash_equals). Only "the issue was
 * completed/closed" is acted on; every other event is acknowledged and
 * ignored.
 */
final class IssueTrackerWebhook
{
    public const REPLAY_WINDOW_MS = 60_000;

    public static function signatureValid(IssueTrackerProvider $provider, string $secret, string $rawBody, string $signature): bool
    {
        if ($secret === '' || $signature === '') {
            return false;
        }
        $expected = hash_hmac('sha256', $rawBody, $secret);
        if ($provider === IssueTrackerProvider::GitHub) {
            $expected = 'sha256=' . $expected;
        }

        return hash_equals($expected, strtolower(trim($signature)));
    }

    /** @param array<array-key, mixed> $payload */
    public static function withinReplayWindow(IssueTrackerProvider $provider, array $payload, int $nowMs): bool
    {
        if ($provider === IssueTrackerProvider::GitHub) {
            return true;
        }
        $timestamp = $payload['webhookTimestamp'] ?? null;

        return is_int($timestamp) && abs($nowMs - $timestamp) <= self::REPLAY_WINDOW_MS;
    }

    /**
     * The provider-side issue id when this delivery says "issue completed /
     * closed as done", otherwise null.
     *
     *  - Linear: type "Issue", action "update"/"create", data.state.type
     *    "completed" (a cancelled issue is NOT treated as done).
     *  - GitHub: event "issues", action "closed", state_reason not
     *    "not_planned", and the repository must be the connected one.
     *
     * @param array<array-key, mixed> $payload
     */
    public static function completedIssueId(IssueTrackerProvider $provider, string $githubEvent, array $payload, string $target): ?string
    {
        if ($provider === IssueTrackerProvider::Linear) {
            $data  = is_array($payload['data'] ?? null) ? $payload['data'] : [];
            $state = is_array($data['state'] ?? null) ? $data['state'] : [];
            $id    = $data['id'] ?? null;
            if (($payload['type'] ?? null) !== 'Issue'
                || !in_array($payload['action'] ?? null, ['update', 'create'], true)
                || ($state['type'] ?? null) !== 'completed'
                || !is_string($id) || $id === '' || strlen($id) > 64) {
                return null;
            }

            return $id;
        }

        $issue = is_array($payload['issue'] ?? null) ? $payload['issue'] : [];
        $repo  = is_array($payload['repository'] ?? null) ? $payload['repository'] : [];
        $id    = $issue['id'] ?? null;
        if ($githubEvent !== 'issues'
            || ($payload['action'] ?? null) !== 'closed'
            || ($issue['state_reason'] ?? null) === 'not_planned'
            || !is_string($repo['full_name'] ?? null) || strcasecmp($repo['full_name'], $target) !== 0
            || !is_int($id)) {
            return null;
        }

        return (string) $id;
    }
}
