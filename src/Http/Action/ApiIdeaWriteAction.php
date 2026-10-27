<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Domain\BoardPhases;
use Votepit\Domain\Premoderation;
use Votepit\Http\Middleware\AccountContextMiddleware;
use Votepit\Http\Middleware\ApiTokenAuthMiddleware;
use Votepit\Http\Middleware\AuthNMiddleware;
use Votepit\Http\Support\FrozenBoardGuard;
use Votepit\Http\Support\JsonError;
use Votepit\Logging\AuditLogger;
use Votepit\Persistence\AccountMemberRepository;
use Votepit\Persistence\BoardRepository;
use Votepit\Persistence\BoardStatusRepository;
use Votepit\Persistence\IdeaRepository;
use Votepit\Persistence\VoteRepository;
use Votepit\Security\AnonymousVoterHasher;
use Votepit\Security\ApiTokenScope;
use Votepit\Support\Clock;

/**
 * Agent API write endpoints on a single idea (competitor-parity issue 15):
 *
 *   POST   /api/v1/ideas/{id}/vote             {value: up|down|none}  write
 *   POST   /api/v1/ideas/{id}/comments         {body}                 write
 *   POST   /api/v1/ideas/{id}/status           {status: <key>, reason?, next_step?, responsible_role?}        moderate
 *   POST   /api/v1/ideas/{id}/tags             {tag_id}               moderate
 *   DELETE /api/v1/ideas/{id}/tags/{tagId}                            moderate
 *   POST   /api/v1/ideas/{id}/merge            {target_id}            moderate
 *   POST   /api/v1/ideas/{id}/unmerge                                 moderate
 *
 * AuthZ: ApiTokenAuthMiddleware (board + account exclusively from the
 * token grant, never from the request) plus the per-endpoint grant level
 * checked here (ApiTokenScope). The `moderate` level is an explicit grant
 * AND additionally requires the token's issuing user to still hold a
 * moderating account role (owner/admin/moderator) — a demoted or removed
 * admin's token loses moderator power immediately (fail-secure).
 *
 * No duplicated domain logic: every endpoint except vote delegates to the
 * SAME action classes the session UI uses (CommentCreateAction,
 * IdeaStatusAction, IdeaTagAssign/RemoveAction, IdeaMergeAction) with an
 * adapted request — the board slug resolved account-scoped from the
 * token's board id, the issuing user as the acting user (ATTR_USER) and
 * only the endpoint's whitelisted body fields. Validation, board/idea
 * scoping (foreign ids → 404), frozen-board guard, status registry,
 * notifications, webhooks and the chat fan-out are therefore exactly the
 * UI's. Comments are attributed to the issuing user, like API-created ideas.
 *
 * Vote semantics: the vote belongs to the token itself (a per-token service
 * identity), NOT to the issuing user — one vote per token per idea, kept
 * apart from the admin's own UI vote. Stored like an anonymous vote, keyed
 * by HMAC("anon-vote-token:api-token:<token_id>") (AnonymousVoterHasher; raw
 * cookie tokens are base64url and can never contain ':' — no collision with
 * a browser identity). `value` is the desired end state (idempotent SET,
 * VoteRepository::setAnonymous()): up/down cast or change it, none removes
 * it, repeating is a no-op. Same 404 (unknown/foreign/pending/merged idea),
 * 423 (frozen board, status closed for votes) and 422 (invalid value) as the
 * UI vote, plus the board's optional voting window (423 voting_closed /
 * voting_not_open_yet); votes fire no webhook (neither does the UI vote). Revoking the
 * token keeps its votes, like a deleted user's anonymised votes.
 */
final readonly class ApiIdeaWriteAction
{
    /** Request attribute marking the audit-log origin ("api_token" REST vs. "mcp"). */
    public const ATTR_VIA = 'api_via';

    /** Prefix of the vote identity's pre-image, see class doc. */
    private const VOTE_IDENTITY_PREFIX = 'api-token:';

    public function __construct(
        private BoardRepository $boardRepo,
        private IdeaRepository $ideaRepo,
        private VoteRepository $voteRepo,
        private BoardStatusRepository $statusRepo,
        private AccountMemberRepository $members,
        private AnonymousVoterHasher $voterHasher,
        private AuditLogger $audit,
        private Clock $clock,
        private CommentCreateAction $commentCreate,
        private IdeaStatusAction $statusChange,
        private IdeaTagAssignAction $tagAssign,
        private IdeaTagRemoveAction $tagRemove,
        private IdeaMergeAction $merge,
    ) {}

    /** @param array<string, mixed> $args */
    public function vote(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $token = $this->authorize($request, $response, ApiTokenScope::WRITE);
        if ($token instanceof ResponseInterface) {
            return $token;
        }

        $board = $this->board($request, $token);
        if ($board === null) {
            return JsonError::respond($response, 404, 'not_found', 'Board not found.');
        }
        if (FrozenBoardGuard::isFrozen($board)) {
            return FrozenBoardGuard::reject($response);
        }

        $boardId = (int) $board['id'];
        $ideaId  = is_numeric($args['id'] ?? null) ? (int) $args['id'] : 0;
        $idea    = $this->ideaRepo->findInBoard($boardId, $ideaId);
        if (!is_array($idea)) {
            return JsonError::respond($response, 404, 'not_found', 'Idea not found.');
        }

        // Optional voting window (broaden-use-cases issue 04) — binds the
        // token exactly like VoteAction binds every voter.
        $votingRejection = BoardPhases::fromBoard($board, $this->clock->now())->votingRejection();
        if ($votingRejection !== null) {
            return JsonError::respond($response, 423, $votingRejection, $votingRejection === 'voting_closed'
                ? 'Voting on this board has closed.'
                : 'Voting on this board has not opened yet.');
        }

        if ($this->statusRepo->registryFor($board)->find((string) $idea['status_key'])?->allowVotes === false) {
            return JsonError::respond($response, 423, 'status_closed_for_votes', 'Voting is closed for ideas in this status.');
        }

        $parsed = $request->getParsedBody();
        $raw    = is_array($parsed) && is_string($parsed['value'] ?? null) ? $parsed['value'] : '';
        $value  = match ($raw) {
            'up'    => 1,
            'down'  => -1,
            'none'  => 0,
            default => null,
        };
        if ($value === null) {
            return JsonError::respond($response, 422, 'invalid_vote_value', 'Invalid vote value — use up, down or none.');
        }

        $identity = $this->voterHasher->hashToken(self::VOTE_IDENTITY_PREFIX . $token['token_id']);
        $result   = $this->voteRepo->setAnonymous($boardId, $ideaId, $identity, $value);

        $this->audit->log('vote.cast_api', [
            'board_id' => $boardId,
            'idea_id'  => $ideaId,
            'value'    => $raw,
            'result'   => $result['my_vote'],
            'token_id' => $token['token_id'],
            'via'      => $this->via($request),
        ]);

        $response->getBody()->write((string) json_encode([
            'score'      => $result['score'],
            'my_vote'    => $result['my_vote'],
            'up_count'   => $result['up_count'],
            'down_count' => $result['down_count'],
        ]));

        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }

    /** @param array<string, mixed> $args */
    public function comment(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->delegate($request, $response, $args, 'comment', ApiTokenScope::WRITE, $this->commentCreate, ['body']);
    }

    /** @param array<string, mixed> $args */
    public function status(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->delegate($request, $response, $args, 'status', ApiTokenScope::MODERATE, $this->statusChange, ['status', 'reason', 'next_step', 'responsible_role']);
    }

    /** @param array<string, mixed> $args */
    public function assignTag(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->delegate($request, $response, $args, 'tag_assign', ApiTokenScope::MODERATE, $this->tagAssign, ['tag_id']);
    }

    /** @param array<string, mixed> $args */
    public function removeTag(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->delegate($request, $response, $args, 'tag_remove', ApiTokenScope::MODERATE, $this->tagRemove, []);
    }

    /** @param array<string, mixed> $args */
    public function mergeInto(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->delegate($request, $response, $args, 'merge', ApiTokenScope::MODERATE, $this->merge->merge(...), ['target_id']);
    }

    /** @param array<string, mixed> $args */
    public function unmerge(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->delegate($request, $response, $args, 'unmerge', ApiTokenScope::MODERATE, $this->merge->unmerge(...), []);
    }

    /**
     * Runs a session-UI action on behalf of the token (see class doc).
     *
     * @param array<string, mixed> $args
     * @param callable(ServerRequestInterface, ResponseInterface, array<string, mixed>): ResponseInterface $handler
     * @param list<string> $fields body fields passed through (scalars only)
     */
    private function delegate(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
        string $action,
        string $requiredScope,
        callable $handler,
        array $fields,
    ): ResponseInterface {
        $token = $this->authorize($request, $response, $requiredScope);
        if ($token instanceof ResponseInterface) {
            return $token;
        }

        $board = $this->board($request, $token);
        if ($board === null) {
            return JsonError::respond($response, 404, 'not_found', 'Board not found.');
        }

        $parsed = $request->getParsedBody();
        $body   = [];
        foreach ($fields as $field) {
            $value = is_array($parsed) ? ($parsed[$field] ?? null) : null;
            if (is_scalar($value)) {
                $body[$field] = (string) $value;
            }
        }

        $ideaId    = is_numeric($args['id'] ?? null) ? (int) $args['id'] : 0;
        $innerArgs = ['board' => (string) $board['slug'], 'id' => (string) $ideaId];
        if (isset($args['tagId']) && is_numeric($args['tagId'])) {
            $innerArgs['tagId'] = (string) (int) $args['tagId'];
        }

        $inner = $request
            ->withAttribute(AuthNMiddleware::ATTR_USER, ['id' => $token['created_by_user_id']])
            ->withParsedBody($body);

        $result = $handler($inner, $response, $innerArgs);

        $this->audit->log('api_token.write', [
            'action'   => $action,
            'board_id' => (int) $board['id'],
            'idea_id'  => $ideaId,
            'token_id' => $token['token_id'],
            'via'      => $this->via($request),
            'status'   => $result->getStatusCode(),
        ]);

        return $result;
    }

    /**
     * The resolved grant if it covers $requiredScope, else the 403 response
     * (`insufficient_scope`, or `token_issuer_not_moderator` when a moderate
     * grant's issuing user no longer moderates the account).
     *
     * @return array{account_id: int, scope: string, token_id: int, created_by_user_id: int, label: string}|ResponseInterface
     */
    private function authorize(ServerRequestInterface $request, ResponseInterface $response, string $requiredScope): array|ResponseInterface
    {
        /** @var array{account_id: int, scope: string, token_id: int, created_by_user_id: int, label: string}|null $token */
        $token    = $request->getAttribute(ApiTokenAuthMiddleware::ATTR_TOKEN);
        $moderate = $requiredScope === ApiTokenScope::MODERATE;
        $scope    = is_array($token) ? $token['scope'] : '';

        if (!is_array($token) || !($moderate ? ApiTokenScope::allowsModerate($scope) : ApiTokenScope::allowsWrite($scope))) {
            return ApiIdeaAction::insufficientScope($response, $moderate
                ? 'This action requires a token with the moderate grant for this board.'
                : 'This token is read-only.');
        }

        if ($moderate && !Premoderation::canModerate($this->members->roleFor($token['account_id'], $token['created_by_user_id']))) {
            return JsonError::respond($response, 403, 'token_issuer_not_moderator', 'The user who issued this token no longer moderates this account.');
        }

        return $token;
    }

    /**
     * The token's resolved board, account-scoped (structurally always found
     * for a live grant — null only if it was deleted mid-request).
     *
     * @param array{account_id: int, scope: string, token_id: int, created_by_user_id: int, label: string} $token
     * @return array<string, mixed>|null
     */
    private function board(ServerRequestInterface $request, array $token): ?array
    {
        $boardId   = (int) $request->getAttribute(ApiTokenAuthMiddleware::ATTR_BOARD_ID);
        $accountId = (int) $request->getAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID);
        if ($accountId !== $token['account_id']) {
            return null; // defense in depth: both come from the same grant
        }

        return $this->boardRepo->findByIdForAccount($boardId, $accountId);
    }

    private function via(ServerRequestInterface $request): string
    {
        return $request->getAttribute(self::ATTR_VIA) === 'mcp' ? 'mcp' : 'api_token';
    }
}
