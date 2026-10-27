<?php

declare(strict_types=1);

namespace Votepit\IssueTracker;

/**
 * The two Linear/GitHub API operations Votepit needs, behind the
 * IssueTrackerHttpClient seam:
 *
 *  - verify(): checks a token + target at connect time (Linear: GraphQL
 *    team lookup by key → team UUID; GitHub: GET /repos/{owner}/{repo}) so
 *    a typo or a token without access is rejected before anything is
 *    stored;
 *  - createIssue(): Linear issueCreate mutation / GitHub POST
 *    /repos/{owner}/{repo}/issues.
 *
 * URLs are built here from validated parts only, always against the
 * provider's fixed API host. Failures surface as IssueTrackerException with
 * a stable key — provider error bodies are never passed through (they may
 * echo request data), and the token never appears in any message.
 */
final readonly class IssueTrackerApi
{
    private const LINEAR_URL  = 'https://api.linear.app/graphql';
    private const GITHUB_BASE = 'https://api.github.com/repos/';

    public function __construct(private IssueTrackerHttpClient $http) {}

    /**
     * @param string $target GitHub "owner/repo" or Linear team key (shape already validated)
     * @return array{target: string, target_id: string} canonical target + provider-side id
     * @throws IssueTrackerException
     */
    public function verify(IssueTrackerProvider $provider, string $token, string $target): array
    {
        if ($provider === IssueTrackerProvider::Linear) {
            $response = $this->call($provider, 'POST', self::LINEAR_URL, $token, [
                'query'     => 'query VotepitTeam($key: String!) { teams(filter: { key: { eq: $key } }) { nodes { id key } } }',
                'variables' => ['key' => strtoupper($target)],
            ]);
            $this->assertLinearOk($response);
            $nodes = $this->path($response['body'], ['data', 'teams', 'nodes']);
            $team  = is_array($nodes) && isset($nodes[0]) && is_array($nodes[0]) ? $nodes[0] : null;
            $id    = is_array($team) && is_string($team['id'] ?? null) ? $team['id'] : '';
            $key   = is_array($team) && is_string($team['key'] ?? null) ? $team['key'] : '';
            if ($id === '' || strlen($id) > 64 || preg_match('/^' . IssueTrackerProvider::LINEAR_TEAM_KEY_PATTERN . '$/', $key) !== 1) {
                throw new IssueTrackerException(IssueTrackerException::TARGET_NOT_FOUND);
            }

            return ['target' => $key, 'target_id' => $id];
        }

        $response = $this->call($provider, 'GET', self::GITHUB_BASE . $target, $token);
        if ($response['status'] === 401) {
            throw new IssueTrackerException(IssueTrackerException::INVALID_CREDENTIALS);
        }
        if (in_array($response['status'], [403, 404], true)) {
            throw new IssueTrackerException(IssueTrackerException::TARGET_NOT_FOUND);
        }
        if ($response['status'] !== 200) {
            throw new IssueTrackerException(IssueTrackerException::UNAVAILABLE);
        }
        $fullName = is_string($response['body']['full_name'] ?? null) ? $response['body']['full_name'] : '';
        if (($response['body']['has_issues'] ?? true) === false
            || preg_match('#^' . IssueTrackerProvider::GITHUB_REPO_PATTERN . '$#', $fullName) !== 1) {
            throw new IssueTrackerException(IssueTrackerException::TARGET_NOT_FOUND);
        }

        return ['target' => $fullName, 'target_id' => ''];
    }

    /**
     * @return array{external_id: string, key: string, url: string}
     * @throws IssueTrackerException
     */
    public function createIssue(
        IssueTrackerProvider $provider,
        string $token,
        string $target,
        string $targetId,
        string $title,
        string $body,
    ): array {
        if ($provider === IssueTrackerProvider::Linear) {
            $response = $this->call($provider, 'POST', self::LINEAR_URL, $token, [
                'query'     => 'mutation VotepitIssue($input: IssueCreateInput!) { issueCreate(input: $input) { success issue { id identifier url } } }',
                'variables' => ['input' => ['teamId' => $targetId, 'title' => $title, 'description' => $body]],
            ]);
            $this->assertLinearOk($response, IssueTrackerException::REJECTED);
            $issue = $this->path($response['body'], ['data', 'issueCreate', 'issue']);
            if (!is_array($issue) || $this->path($response['body'], ['data', 'issueCreate', 'success']) !== true) {
                throw new IssueTrackerException(IssueTrackerException::REJECTED);
            }

            return $this->createdIssue($provider, $issue['id'] ?? null, $issue['identifier'] ?? null, $issue['url'] ?? null);
        }

        $response = $this->call($provider, 'POST', self::GITHUB_BASE . $target . '/issues', $token, [
            'title' => $title,
            'body'  => $body,
        ]);
        if ($response['status'] === 401) {
            throw new IssueTrackerException(IssueTrackerException::INVALID_CREDENTIALS);
        }
        if (in_array($response['status'], [403, 404, 410, 422], true)) {
            throw new IssueTrackerException(IssueTrackerException::REJECTED);
        }
        if ($response['status'] !== 201) {
            throw new IssueTrackerException(IssueTrackerException::UNAVAILABLE);
        }
        $number = $response['body']['number'] ?? null;

        return $this->createdIssue(
            $provider,
            is_int($response['body']['id'] ?? null) ? (string) $response['body']['id'] : null,
            is_int($number) ? '#' . $number : null,
            $response['body']['html_url'] ?? null,
        );
    }

    /**
     * @param array<array-key, mixed>|null $json
     * @return array{status: int, body: array<array-key, mixed>}
     * @throws IssueTrackerException
     */
    private function call(IssueTrackerProvider $provider, string $method, string $url, string $token, ?array $json = null): array
    {
        $headers = $provider === IssueTrackerProvider::Linear
            // Linear personal API keys are sent as-is (no "Bearer" prefix).
            ? ['Authorization: ' . $token]
            : ['Authorization: Bearer ' . $token, 'Accept: application/vnd.github+json', 'X-GitHub-Api-Version: 2022-11-28'];

        try {
            return $this->http->send($provider, $method === 'GET' ? 'GET' : 'POST', $url, $headers, $json);
        } catch (IssueTrackerHttpException) {
            throw new IssueTrackerException(IssueTrackerException::UNAVAILABLE);
        }
    }

    /**
     * Linear answers GraphQL errors with 200 + `errors` or with a 4xx;
     * authentication failures are reported as 400/401 with an
     * AUTHENTICATION_ERROR code.
     *
     * @param array{status: int, body: array<array-key, mixed>} $response
     * @throws IssueTrackerException
     */
    private function assertLinearOk(array $response, string $otherErrorKey = IssueTrackerException::TARGET_NOT_FOUND): void
    {
        $status = $response['status'];
        $errors = $response['body']['errors'] ?? null;
        if ($status === 401 || $status === 403 || $this->hasAuthError($errors)) {
            throw new IssueTrackerException(IssueTrackerException::INVALID_CREDENTIALS);
        }
        if ($status >= 500 || ($status !== 200 && $status !== 400)) {
            throw new IssueTrackerException(IssueTrackerException::UNAVAILABLE);
        }
        if ($status === 400 || (is_array($errors) && $errors !== [])) {
            throw new IssueTrackerException($otherErrorKey);
        }
    }

    private function hasAuthError(mixed $errors): bool
    {
        if (!is_array($errors)) {
            return false;
        }
        foreach ($errors as $error) {
            $code = is_array($error) ? $this->path($error, ['extensions', 'code']) : null;
            if (is_string($code) && in_array(strtoupper($code), ['AUTHENTICATION_ERROR', 'FORBIDDEN'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Validates the provider's answer before it is ever stored or rendered:
     * the URL must be a plain https link on the provider's own web host.
     *
     * @return array{external_id: string, key: string, url: string}
     * @throws IssueTrackerException
     */
    private function createdIssue(IssueTrackerProvider $provider, mixed $id, mixed $key, mixed $url): array
    {
        if (!is_string($id) || $id === '' || strlen($id) > 64
            || !is_string($key) || $key === '' || strlen($key) > 64
            || !is_string($url) || preg_match($provider->issueUrlPattern(), $url) !== 1) {
            throw new IssueTrackerException(IssueTrackerException::UNAVAILABLE);
        }

        return ['external_id' => $id, 'key' => $key, 'url' => $url];
    }

    /**
     * @param array<array-key, mixed> $data
     * @param list<string>            $keys
     */
    private function path(array $data, array $keys): mixed
    {
        $node = $data;
        foreach ($keys as $key) {
            if (!is_array($node) || !array_key_exists($key, $node)) {
                return null;
            }
            $node = $node[$key];
        }

        return $node;
    }
}
