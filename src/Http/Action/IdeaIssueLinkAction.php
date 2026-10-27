<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Domain\AccountPlanResolver;
use Votepit\Domain\PlanPolicy;
use Votepit\Http\Middleware\AccountContextMiddleware;
use Votepit\Http\Middleware\AuthNMiddleware;
use Votepit\Http\Support\FrozenBoardGuard;
use Votepit\IssueTracker\IssueTrackerApi;
use Votepit\IssueTracker\IssueTrackerException;
use Votepit\IssueTracker\IssueTrackerProvider;
use Votepit\Logging\AuditLogger;
use Votepit\Persistence\AccountRepository;
use Votepit\Persistence\BoardRepository;
use Votepit\Persistence\IdeaIssueLinkRepository;
use Votepit\Persistence\IdeaRepository;
use Votepit\Persistence\IssueTrackerConnectionRepository;
use Votepit\Security\EncryptionService;

/**
 * Issue links of one idea (AuthZ: accountModerate — owner, admin or
 * moderator).
 *
 *   GET    /admin/boards/{slug}/ideas/{id}/issue-links             — { allowed, providers, links }
 *   POST   /admin/boards/{slug}/ideas/{id}/issue-links/{provider}  — create the issue + link (plan-gated)
 *   DELETE /admin/boards/{slug}/ideas/{id}/issue-links/{provider}  — remove the link only (the
 *                                                                    issue itself stays in the tracker)
 *
 * Account-/board-scoped: board via findBySlugForAccount, idea via
 * findInBoard (published ideas only — a pending idea is never sent to a
 * third-party system); anything foreign → 404 with no outbound call.
 *
 * The issue body carries the idea text inside a fenced code block (longer
 * fence than any backtick run in the text), so voter-written content cannot
 * @-mention people, embed images or inject markdown in the customer's
 * tracker, plus the link back to the idea.
 */
final readonly class IdeaIssueLinkAction
{
    private const MAX_BODY_CHARS = 4000;

    public function __construct(
        private string $appUrl,
        private bool $accountPrefixedRoutes,
        private BoardRepository $boardRepo,
        private IdeaRepository $ideaRepo,
        private AccountRepository $accountRepo,
        private AccountPlanResolver $plans,
        private PlanPolicy $planPolicy,
        private IssueTrackerConnectionRepository $connections,
        private IdeaIssueLinkRepository $links,
        private IssueTrackerApi $api,
        private EncryptionService $tokenEnc,
        private AuditLogger $audit,
    ) {}

    /** @param array<string, mixed> $args */
    public function get(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $accountId = $this->accountId($request);
        $scope     = $this->resolve($accountId, $args);
        if ($scope === null) {
            return $this->notFound($response);
        }

        $providers = array_map(
            static fn (array $row): string => $row['provider'],
            $this->connections->listForAccount($accountId),
        );

        return $this->json($response, 200, [
            'allowed'   => $this->allowed($request, $accountId),
            'providers' => $providers,
            'links'     => $this->links->listForIdea($accountId, (int) $scope['idea']['id']),
        ]);
    }

    /** @param array<string, mixed> $args */
    public function post(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $accountId = $this->accountId($request);
        $scope     = $this->resolve($accountId, $args);
        $provider  = IssueTrackerProvider::tryFrom(is_string($args['provider'] ?? null) ? $args['provider'] : '');
        if ($scope === null || $provider === null) {
            return $this->notFound($response);
        }
        if (FrozenBoardGuard::isFrozen($scope['board'])) {
            return FrozenBoardGuard::reject($response);
        }
        if (!$this->allowed($request, $accountId)) {
            return $this->json($response, 422, ['error' => [
                'key'     => 'plan_limit_issue_tracker',
                'message' => 'Linear/GitHub issues are not available on your current plan.',
            ]]);
        }

        $ideaId     = (int) $scope['idea']['id'];
        $connection = $this->connections->findForAccount($accountId, $provider->value);
        $token      = $connection !== null ? $this->tokenEnc->decrypt($connection['token_encrypted']) : null;
        if ($connection === null || $token === null || $token === '') {
            return $this->json($response, 422, ['error' => ['key' => 'issue_tracker_not_connected', 'message' => 'Connect the issue tracker first.']]);
        }
        if ($this->links->exists($accountId, $ideaId, $provider->value)) {
            return $this->json($response, 409, ['error' => ['key' => 'issue_link_exists', 'message' => 'An issue was already created for this idea.']]);
        }

        try {
            $created = $this->api->createIssue(
                $provider,
                $token,
                $connection['target'],
                $connection['target_id'],
                mb_substr(trim((string) $scope['idea']['title']), 0, 255),
                $this->issueBody($scope['idea'], $scope['board'], $accountId),
            );
        } catch (IssueTrackerException $e) {
            return $this->json($response, $e->httpStatus(), ['error' => ['key' => $e->key, 'message' => $e->userMessage()]]);
        }

        $user = $request->getAttribute(AuthNMiddleware::ATTR_USER);
        if (!$this->links->create($accountId, $ideaId, $provider->value, $created['external_id'], $created['key'], $created['url'], is_array($user) ? (int) ($user['id'] ?? 0) : 0)) {
            // Lost a race against a concurrent create for the same idea.
            return $this->json($response, 409, ['error' => ['key' => 'issue_link_exists', 'message' => 'An issue was already created for this idea.']]);
        }

        $this->audit->log('issue_tracker.issue_created', ['account_id' => $accountId, 'idea_id' => $ideaId, 'provider' => $provider->value]);

        return $this->json($response, 201, ['ok' => true, 'link' => [
            'provider'     => $provider->value,
            'external_key' => $created['key'],
            'url'          => $created['url'],
        ]]);
    }

    /** @param array<string, mixed> $args */
    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $accountId = $this->accountId($request);
        $scope     = $this->resolve($accountId, $args);
        $provider  = IssueTrackerProvider::tryFrom(is_string($args['provider'] ?? null) ? $args['provider'] : '');
        if ($scope !== null && FrozenBoardGuard::isFrozen($scope['board'])) {
            return FrozenBoardGuard::reject($response);
        }
        if ($scope === null || $provider === null || !$this->links->delete($accountId, (int) $scope['idea']['id'], $provider->value)) {
            return $this->notFound($response);
        }

        $this->audit->log('issue_tracker.link_removed', ['account_id' => $accountId, 'idea_id' => (int) $scope['idea']['id'], 'provider' => $provider->value]);

        return $this->json($response, 200, ['ok' => true]);
    }

    /**
     * @param array<string, mixed> $args
     * @return array{board: array<string, mixed>, idea: array<string, mixed>}|null
     */
    private function resolve(int $accountId, array $args): ?array
    {
        $slug  = is_string($args['slug'] ?? null) ? $args['slug'] : '';
        $board = $this->boardRepo->findBySlugForAccount($slug, $accountId);
        $idea  = is_array($board) ? $this->ideaRepo->findInBoard((int) $board['id'], (int) ($args['id'] ?? 0)) : null;

        return is_array($board) && is_array($idea) ? ['board' => $board, 'idea' => $idea] : null;
    }

    /**
     * @param array<string, mixed> $idea
     * @param array<string, mixed> $board
     */
    private function issueBody(array $idea, array $board, int $accountId): string
    {
        $text = mb_substr(trim((string) ($idea['body'] ?? '')), 0, self::MAX_BODY_CHARS);
        preg_match_all('/`+/', $text, $runs);
        $longest = max([0, ...array_map(strlen(...), $runs[0])]);
        $fence   = str_repeat('`', max(3, $longest + 1));

        $prefix = '';
        if ($this->accountPrefixedRoutes) {
            $account = $this->accountRepo->findById($accountId);
            $prefix  = '/' . rawurlencode(is_array($account) ? (string) ($account['slug'] ?? '') : '');
        }
        $url = rtrim($this->appUrl, '/') . $prefix . '/' . rawurlencode((string) $board['slug']) . '/idea/' . (int) $idea['id'];

        $quoted = $text !== '' ? $fence . "text\n" . $text . "\n" . $fence . "\n\n" : '';

        return $quoted . 'Votepit idea: ' . $url;
    }

    private function allowed(ServerRequestInterface $request, int $accountId): bool
    {
        $plan    = $this->plans->forAccount($accountId, $request->getAttribute(AuthNMiddleware::ATTR_USER));

        return $this->planPolicy->issueTrackerAllowed($plan);
    }

    private function accountId(ServerRequestInterface $request): int
    {
        return (int) $request->getAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID);
    }

    private function notFound(ResponseInterface $response): ResponseInterface
    {
        return $this->json($response, 404, ['error' => ['key' => 'not_found', 'message' => 'Not found.']]);
    }

    /** @param array<string, mixed> $payload */
    private function json(ResponseInterface $response, int $status, array $payload): ResponseInterface
    {
        $response->getBody()->write((string) json_encode($payload));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
