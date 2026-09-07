<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Http\Middleware\AccountContextMiddleware;
use Votepit\Http\Middleware\AuthNMiddleware;
use Votepit\Persistence\AccountMemberRepository;
use Votepit\Persistence\BoardRepository;
use Votepit\Security\TimeTrapService;

/**
 * GET /{board}/ideas/new — SPA route: returns board info + auth status +
 * time-trap stamp (AuthZ: anon). The PRG redirect to login is dropped
 * server-side; the SPA evaluates is_authenticated. form_at must be echoed
 * back by the SPA as the _form_at field in the POST.
 *
 * findPublicBySlugForAccount() additionally gates on board visibility —
 * a 'private' board is structurally unfindable for a non-member → 404.
 */
final readonly class IdeaNewAction
{
    public function __construct(
        private BoardRepository $boardRepo,
        private TimeTrapService $timeTrap,
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

        $user = $request->getAttribute(AuthNMiddleware::ATTR_USER);
        $response->getBody()->write((string) json_encode([
            'board'            => [
                'id'   => (int) $board['id'],
                'slug' => $slug,
                'name' => is_string($board['name'] ?? null) ? $board['name'] : $slug,
            ],
            'is_authenticated' => $user !== null,
            'form_at'          => $this->timeTrap->stamp(),
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
