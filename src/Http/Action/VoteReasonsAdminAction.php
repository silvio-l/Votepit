<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Http\Middleware\AccountContextMiddleware;
use Votepit\Http\Support\JsonError;
use Votepit\Persistence\BoardRepository;
use Votepit\Persistence\IdeaRepository;
use Votepit\Persistence\VoteReasonRepository;

/**
 * GET /admin/boards/{slug}/ideas/{id}/vote-reasons — operator aggregate of the
 * P-03 down-vote reasons for one idea (owner rule Q4 = 4a).
 *
 * AuthZ: accountModerate (owner|admin|moderator) at route level; members,
 * guests and the public never reach this action. Board and idea are loaded
 * account- and board-scoped (foreign → 404). Readable on frozen boards.
 *
 * Response: { enabled, down_count, counts: {per reason}, notes: [{reason, note}] }.
 * No identifiers, no names, no e-mail, no per-vote timestamps. Free texts are
 * returned as JSON strings for plain-text rendering only.
 */
final readonly class VoteReasonsAdminAction
{
    public function __construct(
        private BoardRepository $boardRepo,
        private IdeaRepository $ideaRepo,
        private VoteReasonRepository $reasonRepo,
    ) {}

    /** @param array<string, mixed> $args */
    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $slug      = is_string($args['slug'] ?? null) ? $args['slug'] : '';
        $accountId = (int) $request->getAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID);
        $board     = $this->boardRepo->findBySlugForAccount($slug, $accountId);
        if (!is_array($board)) {
            return JsonError::respond($response, 404, 'not_found', 'Board not found.');
        }

        $boardId = (int) $board['id'];
        $ideaId  = (int) ($args['id'] ?? 0);
        if (!is_array($this->ideaRepo->findInBoard($boardId, $ideaId))) {
            return JsonError::respond($response, 404, 'not_found', 'Idea not found.');
        }

        $aggregate = $this->reasonRepo->aggregateForIdea($ideaId);

        $response->getBody()->write((string) json_encode([
            'enabled'    => (bool) ($board['vote_reasons_enabled'] ?? false),
            'down_count' => $aggregate['down_count'],
            'counts'     => $aggregate['counts'],
            'notes'      => $aggregate['notes'],
        ]));

        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }
}
