<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Http\Middleware\AccountContextMiddleware;
use Votepit\Http\Middleware\AuthNMiddleware;
use Votepit\Persistence\AccountMemberRepository;
use Votepit\Persistence\BoardRepository;
use Votepit\Persistence\CommentRepository;
use Votepit\Persistence\IdeaRepository;

/**
 * GET /{board}/ideas/{id} — idea detail view.
 *
 * AuthZ: anon (reading is public). Unknown slug or idea → 404.
 * Cross-board leak prevented by board-scoped findInBoard().
 *
 * Also returns the flat comment list of the idea (`comments`) —
 * CommentRepository::listByIdea() is idea-scoped, the idea itself has
 * already been loaded board-scoped at this point.
 *
 * findPublicBySlugForAccount() additionally gates on board visibility —
 * a 'private' board is structurally unfindable for a non-member → 404.
 */
final readonly class IdeaDetailAction
{
    public function __construct(
        private BoardRepository $boardRepo,
        private IdeaRepository $ideaRepo,
        private CommentRepository $commentRepo,
        private AccountMemberRepository $accountMembers,
    ) {}

    /** @param array<string, mixed> $args */
    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $slug      = is_string($args['board'] ?? null) ? $args['board'] : '';
        $accountId = (int) $request->getAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID);

        $viewerIsMember = $this->viewerIsMember($request, $accountId);
        $board          = $this->boardRepo->findPublicBySlugForAccount($slug, $accountId, $viewerIsMember);
        if (!is_array($board)) {
            $response->getBody()->write((string) json_encode([
                'error' => ['key' => 'not_found', 'message' => 'Board not found.'],
            ]));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        $ideaId      = (int) ($args['id'] ?? 0);
        $currentUser = $request->getAttribute(AuthNMiddleware::ATTR_USER);
        // my_vote via a set-based subquery when logged in.
        $currentUserId = is_array($currentUser) ? (int) ($currentUser['id'] ?? 0) : null;

        $idea = $this->ideaRepo->findInBoard((int) $board['id'], $ideaId, $currentUserId);
        if (!is_array($idea)) {
            $response->getBody()->write((string) json_encode([
                'error' => ['key' => 'not_found', 'message' => 'Idea not found.'],
            ]));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        $comments = $this->commentRepo->listByIdea($ideaId);

        $response->getBody()->write((string) json_encode([
            'board'            => [
                'id'   => (int) $board['id'],
                'slug' => $slug,
                'name' => is_string($board['name'] ?? null) ? $board['name'] : $slug,
            ],
            'idea'             => $idea,
            'comments'         => $comments,
            'is_authenticated' => $currentUser !== null,
        ]));
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }

    private function viewerIsMember(ServerRequestInterface $request, int $accountId): bool
    {
        $user   = $request->getAttribute(AuthNMiddleware::ATTR_USER);
        $userId = is_array($user) ? (int) ($user['id'] ?? 0) : 0;

        return $userId > 0 && $this->accountMembers->roleFor($accountId, $userId) !== null;
    }
}
