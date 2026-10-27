<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Domain\AccountPlanResolver;
use Votepit\Domain\PlanPolicy;
use Votepit\Http\Middleware\AccountContextMiddleware;
use Votepit\Http\Middleware\AuthNMiddleware;
use Votepit\IssueTracker\IssueTrackerApi;
use Votepit\IssueTracker\IssueTrackerException;
use Votepit\IssueTracker\IssueTrackerProvider;
use Votepit\Logging\AuditLogger;
use Votepit\Persistence\IssueTrackerConnectionRepository;
use Votepit\Security\EncryptionService;

/**
 * Account-level Linear/GitHub connection (AuthZ: accountAdmin, same tier as
 * API tokens).
 *
 *   GET    /admin/issue-trackers             — { allowed, connections: { linear, github } }
 *   PUT    /admin/issue-trackers/{provider}  — connect / update (plan-gated)
 *   DELETE /admin/issue-trackers/{provider}  — disconnect (never plan-gated)
 *
 * PUT body: `token` (Linear API key / GitHub fine-grained PAT; optional when
 * already connected — the stored one is reused), `team_key` (Linear) or
 * `repository` ("owner/repo", GitHub), and for Linear the optional
 * `webhook_secret` (the signing secret Linear shows for the webhook the
 * admin creates with the returned webhook_url). The token + target are
 * verified against the provider BEFORE anything is stored.
 *
 * Secrets: the token and webhook secret are stored encrypted (own
 * key-separation contexts) and never returned. For GitHub Votepit
 * generates the webhook secret on first connect and returns it in that one
 * response only (the admin pastes it into GitHub's webhook form).
 */
final readonly class IssueTrackerConnectionAction
{
    private const TOKEN_PATTERN          = '/^[\x21-\x7E]{8,512}$/';
    private const WEBHOOK_SECRET_PATTERN = '/^[\x21-\x7E]{8,256}$/';

    public function __construct(
        private string $appUrl,
        private AccountPlanResolver $plans,
        private PlanPolicy $planPolicy,
        private IssueTrackerConnectionRepository $repo,
        private IssueTrackerApi $api,
        private EncryptionService $tokenEnc,
        private EncryptionService $webhookSecretEnc,
        private AuditLogger $audit,
    ) {}

    public function get(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $connections = [];
        foreach (IssueTrackerProvider::cases() as $provider) {
            $connections[$provider->value] = null;
        }
        foreach ($this->repo->listForAccount($this->accountId($request)) as $row) {
            if (array_key_exists($row['provider'], $connections)) {
                $connections[$row['provider']] = $this->present($row);
            }
        }

        return $this->json($response, 200, ['allowed' => $this->allowed($request), 'connections' => $connections]);
    }

    /** @param array<string, mixed> $args */
    public function put(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $provider = IssueTrackerProvider::tryFrom(is_string($args['provider'] ?? null) ? $args['provider'] : '');
        if ($provider === null) {
            return $this->json($response, 404, ['error' => ['key' => 'not_found', 'message' => 'Not found.']]);
        }
        if (!$this->allowed($request)) {
            return $this->json($response, 422, ['error' => [
                'key'     => 'plan_limit_issue_tracker',
                'message' => 'Linear/GitHub issues are not available on your current plan.',
            ]]);
        }

        $accountId = $this->accountId($request);
        $existing  = $this->repo->findForAccount($accountId, $provider->value);
        $parsed    = $request->getParsedBody();
        $body      = is_array($parsed) ? $parsed : [];

        $fields = [];
        $token  = is_string($body['token'] ?? null) ? trim($body['token']) : '';
        $hint   = $token !== '' ? IssueTrackerProvider::tokenHint($token) : ($existing['token_hint'] ?? '');
        if ($token === '' && $existing !== null) {
            $token = (string) $this->tokenEnc->decrypt($existing['token_encrypted']);
        }
        if (preg_match(self::TOKEN_PATTERN, $token) !== 1) {
            $fields['token'] = 'Enter a valid access token.';
        }

        $targetField = $provider === IssueTrackerProvider::Linear ? 'team_key' : 'repository';
        $target      = is_string($body[$targetField] ?? null) ? trim($body[$targetField]) : '';
        $pattern     = $provider === IssueTrackerProvider::Linear ? IssueTrackerProvider::LINEAR_TEAM_KEY_PATTERN : IssueTrackerProvider::GITHUB_REPO_PATTERN;
        if (preg_match('#^' . $pattern . '$#', $target) !== 1) {
            $fields[$targetField] = $provider === IssueTrackerProvider::Linear
                ? 'Enter the Linear team key (e.g. ENG).'
                : 'Enter the repository as owner/repo.';
        }

        $webhookSecret = null;
        if ($provider === IssueTrackerProvider::Linear) {
            $given = is_string($body['webhook_secret'] ?? null) ? trim($body['webhook_secret']) : '';
            if ($given !== '' && preg_match(self::WEBHOOK_SECRET_PATTERN, $given) !== 1) {
                $fields['webhook_secret'] = 'Enter the signing secret shown by Linear.';
            }
            $webhookSecret = $given !== '' ? $given : null;
        } elseif ($existing === null || $existing['webhook_secret_encrypted'] === null) {
            // GitHub: Votepit picks the shared secret (shown once below).
            $webhookSecret = bin2hex(random_bytes(32));
        }

        if ($fields !== []) {
            return $this->json($response, 422, ['error' => ['key' => 'validation_error', 'message' => 'Validation failed.', 'fields' => $fields]]);
        }

        try {
            $verified = $this->api->verify($provider, $token, $target);
        } catch (IssueTrackerException $e) {
            return $this->json($response, $e->httpStatus(), ['error' => ['key' => $e->key, 'message' => $e->userMessage()]]);
        }

        $row = $this->repo->save(
            $accountId,
            $provider->value,
            $this->tokenEnc->encrypt($token),
            $hint,
            $verified['target'],
            $verified['target_id'],
            $webhookSecret !== null ? $this->webhookSecretEnc->encrypt($webhookSecret) : null,
        );

        $this->audit->log('issue_tracker.connected', ['account_id' => $accountId, 'provider' => $provider->value]);

        $payload = ['ok' => true, 'connection' => $this->present($row)];
        if ($provider === IssueTrackerProvider::GitHub && $webhookSecret !== null) {
            $payload['webhook_secret'] = $webhookSecret;
        }

        return $this->json($response, 200, $payload);
    }

    /** @param array<string, mixed> $args */
    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $provider  = IssueTrackerProvider::tryFrom(is_string($args['provider'] ?? null) ? $args['provider'] : '');
        $accountId = $this->accountId($request);
        if ($provider === null || !$this->repo->delete($accountId, $provider->value)) {
            return $this->json($response, 404, ['error' => ['key' => 'not_found', 'message' => 'Not found.']]);
        }

        $this->audit->log('issue_tracker.disconnected', ['account_id' => $accountId, 'provider' => $provider->value]);

        return $this->json($response, 200, ['ok' => true]);
    }

    /**
     * Client view of a connection — never the token or webhook secret.
     *
     * @param array{provider: string, token_hint: string, target: string, webhook_id: string, webhook_secret_encrypted: string|null, updated_at: string} $row
     * @return array<string, mixed>
     */
    private function present(array $row): array
    {
        return [
            'provider'     => $row['provider'],
            'target'       => $row['target'],
            'token_hint'   => $row['token_hint'],
            'webhook_url'  => rtrim($this->appUrl, '/') . '/api/issue-tracker-webhooks/' . $row['provider'] . '/' . $row['webhook_id'],
            'sync_enabled' => $row['webhook_secret_encrypted'] !== null,
            'updated_at'   => $row['updated_at'],
        ];
    }

    private function allowed(ServerRequestInterface $request): bool
    {
        $plan    = $this->plans->forAccount($this->accountId($request), $request->getAttribute(AuthNMiddleware::ATTR_USER));

        return $this->planPolicy->issueTrackerAllowed($plan);
    }

    private function accountId(ServerRequestInterface $request): int
    {
        return (int) $request->getAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID);
    }

    /** @param array<string, mixed> $payload */
    private function json(ResponseInterface $response, int $status, array $payload): ResponseInterface
    {
        $response->getBody()->write((string) json_encode($payload));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
