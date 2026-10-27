<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Http\Middleware\AccountContextMiddleware;
use Votepit\Http\Middleware\AuthNMiddleware;
use Votepit\Http\Support\FrozenBoardGuard;
use Votepit\Http\Support\JsonError;
use Votepit\Persistence\AccountMemberRepository;
use Votepit\Persistence\BoardRepository;
use Votepit\Persistence\IdeaFollowRepository;
use Votepit\Persistence\IdeaRepository;

/**
 * GET / POST / DELETE /{board}/ideas/{id}/follow — follow an idea to get the
 * participant mails about its status changes and new comments (close-the-loop
 * re-engagement B).
 *
 * AuthZ: user (route level). The board is resolved through
 * BoardRepository::findPublicBySlugForAccount — the visibility chokepoint —
 * so a private board is a 404 for anyone who is not a member of its account,
 * and a foreign account's board is a 404 by construction (account scoping
 * from the request, never from the body). The idea must belong to that board.
 */
final readonly class IdeaFollowAction
{
    public function __construct(
        private BoardRepository $boards,
        private IdeaRepository $ideas,
        private IdeaFollowRepository $follows,
        private AccountMemberRepository $members,
    ) {}

    /** @param array<string, mixed> $args */
    public function status(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        // A read: stays answerable on a frozen board (FrozenBoardGuard reads are never guarded).
        $scope = $this->resolve($request, $response, $args, write: false);
        if ($scope instanceof ResponseInterface) {
            return $scope;
        }

        return $this->json($response, ['following' => $this->follows->isFollowing($scope['idea_id'], $scope['user_id'])]);
    }

    /** @param array<string, mixed> $args */
    public function follow(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $scope = $this->resolve($request, $response, $args, write: true);
        if ($scope instanceof ResponseInterface) {
            return $scope;
        }

        $this->follows->follow($scope['idea_id'], $scope['user_id'], $scope['board_id'], new \DateTimeImmutable());

        return $this->json($response, ['following' => true]);
    }

    /** @param array<string, mixed> $args */
    public function unfollow(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $scope = $this->resolve($request, $response, $args, write: true);
        if ($scope instanceof ResponseInterface) {
            return $scope;
        }

        $this->follows->unfollow($scope['idea_id'], $scope['user_id']);

        return $this->json($response, ['following' => false]);
    }

    /**
     * @param array<string, mixed> $args
     * @return array{user_id: int, board_id: int, idea_id: int}|ResponseInterface
     */
    private function resolve(ServerRequestInterface $request, ResponseInterface $response, array $args, bool $write): array|ResponseInterface
    {
        $user   = $request->getAttribute(AuthNMiddleware::ATTR_USER);
        $userId = is_array($user) ? (int) ($user['id'] ?? 0) : 0;
        if ($userId === 0) {
            return JsonError::respond($response, 401, 'unauthorized', 'Sign in to follow ideas.');
        }

        $accountId = (int) $request->getAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID);
        $slug      = is_string($args['board'] ?? null) ? $args['board'] : '';
        $viewerIsMember = $this->members->roleFor($accountId, $userId) !== null;

        $board = $this->boards->findPublicBySlugForAccount($slug, $accountId, $viewerIsMember);
        if (!is_array($board)) {
            return JsonError::respond($response, 404, 'not_found', 'Board not found.');
        }

        if ($write && FrozenBoardGuard::isFrozen($board)) {
            return FrozenBoardGuard::reject($response);
        }

        $boardId = (int) $board['id'];
        $ideaId  = (int) ($args['id'] ?? 0);
        if (!is_array($this->ideas->findInBoard($boardId, $ideaId))) {
            return JsonError::respond($response, 404, 'not_found', 'Idea not found.');
        }

        return ['user_id' => $userId, 'board_id' => $boardId, 'idea_id' => $ideaId];
    }

    /** @param array<string, mixed> $payload */
    private function json(ResponseInterface $response, array $payload): ResponseInterface
    {
        $response->getBody()->write((string) json_encode($payload));

        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }
}
