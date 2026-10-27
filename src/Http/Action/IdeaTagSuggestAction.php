<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Extension\TagSuggester;
use Votepit\Extension\TagSuggestionAvailability;
use Votepit\Http\Middleware\AccountContextMiddleware;
use Votepit\Http\Support\IdeaTagInput;
use Votepit\Persistence\BoardRepository;
use Votepit\Persistence\TagRepository;

/**
 * POST /{board}/ideas/tag-suggestions — AI tag suggestions while the
 * submitter writes (P-35).
 *
 * AuthZ: user (signed-in only, guests get the 401 of AuthZMiddleware::user()).
 * RateLimit `ai:tag-suggest`, attached in IdeaRoutes.
 *
 * Board-scoped: the board is resolved through findBySlugForAccount() (unknown
 * or foreign slug → 404) and the candidate tags are loaded server-side from
 * that board only. The suggester receives the title, the body (both capped)
 * and the tag names and ids of this board — nothing else. Its answer is
 * intersected with the board's tag ids, de-duplicated and capped at the
 * submit limit, so it can never introduce a tag.
 *
 * Fail-secure: without a suggester, when the suggester is not available for
 * the account, when the title is too short or when it gives no verdict, the
 * answer is an empty list (the form keeps working without suggestions).
 */
final readonly class IdeaTagSuggestAction
{
    /** Below this trimmed length, skip the suggester (too little signal). */
    private const MIN_TITLE_LENGTH = 3;

    private const MAX_TITLE_LENGTH = 200;

    private const MAX_BODY_LENGTH = 2000;

    public function __construct(
        private BoardRepository $boardRepo,
        private TagRepository $tagRepo,
        private ?TagSuggester $suggester = null,
    ) {}

    /** @param array<string, mixed> $args */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $slug      = is_string($args['board'] ?? null) ? $args['board'] : '';
        $accountId = (int) $request->getAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID);

        $board = $this->boardRepo->findBySlugForAccount($slug, $accountId);
        if (!is_array($board)) {
            $response->getBody()->write((string) json_encode([
                'error' => ['key' => 'not_found', 'message' => 'Board not found.'],
            ]));

            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        $suggester    = $this->suggester;
        $availability = $suggester instanceof TagSuggester ? $suggester->availability($accountId) : TagSuggestionAvailability::Off;
        $suggestions  = [];

        $parsed = $request->getParsedBody();
        $title  = trim(is_array($parsed) && is_scalar($parsed['title'] ?? null) ? (string) $parsed['title'] : '');
        $body   = trim(is_array($parsed) && is_scalar($parsed['body'] ?? null) ? (string) $parsed['body'] : '');

        if ($suggester instanceof TagSuggester
            && $availability === TagSuggestionAvailability::Available
            && mb_strlen($title) >= self::MIN_TITLE_LENGTH
        ) {
            $tags = array_map(
                static fn (array $row): array => ['id' => $row['id'], 'name' => $row['name']],
                $this->tagRepo->listByBoard((int) $board['id']),
            );
            $verdict = $tags === [] ? null : $suggester->suggest(
                $accountId,
                mb_substr($title, 0, self::MAX_TITLE_LENGTH),
                mb_substr($body, 0, self::MAX_BODY_LENGTH),
                $tags,
            );
            $suggestions = $this->allowed($verdict, $tags);
        }

        $response->getBody()->write((string) json_encode([
            'suggestions'  => $suggestions,
            'availability' => $availability->value,
        ]));

        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }

    /**
     * Keeps only ids that are tags of this board, in the suggester's order,
     * without repeats, capped at the submit limit.
     *
     * @param list<int>|null $verdict
     * @param list<array{id: int, name: string}> $tags
     * @return list<int>
     */
    private function allowed(?array $verdict, array $tags): array
    {
        if ($verdict === null) {
            return [];
        }

        $boardIds = array_column($tags, 'id');
        $picked   = [];
        foreach ($verdict as $id) {
            if (in_array($id, $boardIds, true) && !in_array($id, $picked, true)) {
                $picked[] = $id;
            }
        }

        return array_slice($picked, 0, IdeaTagInput::MAX_TAGS);
    }
}
