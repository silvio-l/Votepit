<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Http\Middleware\AccountContextMiddleware;
use Votepit\Http\Middleware\AuthNMiddleware;
use Votepit\Persistence\AccountMemberRepository;
use Votepit\Persistence\BoardRepository;
use Votepit\Persistence\TagRepository;

/**
 * GET /{board}/tags — list a board's tags (read-only, public).
 *
 * AuthZ: anon (reading is public, same trust level as the idea list/detail
 * routes). Unknown slug → 404. Uses findPublicBySlugForAccount() — a
 * 'private' board is structurally unfindable for a non-member → 404, same
 * visibility gate as BoardHomeAction/IdeaDetailAction.
 *
 * Every voter sees tags read-only here; creating/renaming/deleting a tag
 * requires accountModerate (TagCreateAction/TagUpdateAction/TagDeleteAction).
 */
final readonly class TagListAction
{
    public function __construct(
        private BoardRepository $boardRepo,
        private TagRepository $tagRepo,
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

        $tags = $this->tagRepo->listByBoard((int) $board['id']);

        $response->getBody()->write((string) json_encode(['tags' => $tags]));

        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }

    private function viewerIsMember(ServerRequestInterface $request, int $accountId): bool
    {
        $user   = $request->getAttribute(AuthNMiddleware::ATTR_USER);
        $userId = is_array($user) ? (int) ($user['id'] ?? 0) : 0;

        return $userId > 0 && $this->accountMembers->roleFor($accountId, $userId) !== null;
    }
}
