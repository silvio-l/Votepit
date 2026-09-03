<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validation;
use Votepit\Domain\ContentModerationService;
use Votepit\Http\Middleware\AccountContextMiddleware;
use Votepit\Http\Middleware\AuthNMiddleware;
use Votepit\Http\Support\FrozenBoardGuard;
use Votepit\Logging\AuditLogger;
use Votepit\Persistence\BlockRepository;
use Votepit\Persistence\BoardRepository;
use Votepit\Persistence\CommentRepository;
use Votepit\Persistence\IdeaRepository;
use Votepit\Persistence\ModerationConfigRepository;

/**
 * POST /{board}/ideas/{id}/comments — create a comment.
 *
 * AuthZ: user (via AuthZMiddleware::user() in AppFactory).
 * CSRF: globally enforced (CsrfMiddleware in the POST path).
 * RateLimit `comment:user`: per-action rate limit (attached in AppFactory).
 *
 * Structurally board-scoped: the idea is loaded board-scoped via
 * IdeaRepository::findInBoard() — unknown slug or idea outside the board
 * → 404, no row is created. CommentRepository itself knows no board_id
 * (see the class doc there).
 *
 * Validation: body 1..2000 characters (Symfony Validator). On error → 422
 * + the unified JSON error contract. Success → 201 JSON `{"ok": true, "id": N}`.
 *
 * Plaintext only — shared-origin invariant: the body is never interpreted
 * as HTML/Markdown, neither here nor in the frontend (React escapes text
 * content by default). Runs through the same word-filter moderation as
 * ideas (board toggle + custom word list), reusing ContentModerationService.
 *
 * Board-scoped user-block guard directly here (no central middleware),
 * because the board is already loaded at this point — the accountwide
 * check additionally and unchangedly runs via BlockCheckMiddleware.
 */
final readonly class CommentCreateAction
{
    public function __construct(
        private BoardRepository $boardRepo,
        private IdeaRepository $ideaRepo,
        private CommentRepository $commentRepo,
        private AuditLogger $audit,
        private ContentModerationService $moderation,
        private BlockRepository $blockRepo,
        private ?ModerationConfigRepository $moderationConfigRepo = null,
    ) {}

    /** @param array<string, mixed> $args */
    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $slug      = is_string($args['board'] ?? null) ? $args['board'] : '';
        $accountId = (int) $request->getAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID);
        $board     = $this->boardRepo->findBySlugForAccount($slug, $accountId);
        if (!is_array($board)) {
            $response->getBody()->write((string) json_encode([
                'error' => ['key' => 'not_found', 'message' => 'Board not found.'],
            ]));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        if (FrozenBoardGuard::isFrozen($board)) {
            return FrozenBoardGuard::reject($response);
        }

        $boardId = (int) $board['id'];
        $ideaId  = (int) ($args['id'] ?? 0);

        $idea = $this->ideaRepo->findInBoard($boardId, $ideaId);
        if (!is_array($idea)) {
            $response->getBody()->write((string) json_encode([
                'error' => ['key' => 'not_found', 'message' => 'Idea not found.'],
            ]));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        /** @var array<string, mixed> $user */
        $user   = $request->getAttribute(AuthNMiddleware::ATTR_USER);
        $userId = (int) ($user['id'] ?? 0);

        if ($this->blockRepo->isBlocked($accountId, $userId, $boardId)) {
            $response->getBody()->write((string) json_encode([
                'error' => ['key' => 'blocked', 'message' => 'You are blocked from this board.'],
            ]));
            return $response->withStatus(403)->withHeader('Content-Type', 'application/json');
        }

        $parsed  = $request->getParsedBody();
        $rawBody = is_array($parsed) ? trim((string) ($parsed['body'] ?? '')) : '';

        $validator  = Validation::createValidator();
        $bodyErrors = $validator->validate($rawBody, [
            new Assert\NotBlank(message: 'The comment must not be empty.'),
            new Assert\Length(
                min: 1,
                max: 2000,
                maxMessage: 'The comment must be at most {{ limit }} characters long.',
            ),
        ]);

        /** @var array<string, string> $fields */
        $fields = [];
        foreach ($bodyErrors as $e) {
            $fields['body'] = (string) $e->getMessage();
            break;
        }

        if ($fields !== []) {
            $response->getBody()->write((string) json_encode([
                'error' => [
                    'key'     => 'validation_error',
                    'message' => 'Validation failed.',
                    'fields'  => $fields,
                    'values'  => ['body' => $rawBody],
                ],
            ]));
            return $response->withStatus(422)->withHeader('Content-Type', 'application/json');
        }

        // Moderation hard block: the same board word-filter policy as for ideas.
        $moderationEnabled = !$this->moderationConfigRepo instanceof ModerationConfigRepository
            || $this->moderationConfigRepo->isModerationEnabled($boardId);

        $effectiveModeration = $this->moderation;
        if ($moderationEnabled && $this->moderationConfigRepo instanceof ModerationConfigRepository) {
            $customWords = $this->moderationConfigRepo->wordList($boardId);
            if ($customWords !== []) {
                $effectiveModeration = $this->moderation->withAdditionalWords($customWords);
            }
        }

        $modResult = $moderationEnabled ? $effectiveModeration->check($rawBody) : ['clean' => true, 'hits' => []];
        if (!$modResult['clean']) {
            $this->audit->log('comment.moderation_blocked', [
                'board_id'  => $boardId,
                'idea_id'   => $ideaId,
                'hit_count' => count($modResult['hits']),
            ]);

            $response->getBody()->write((string) json_encode([
                'error' => [
                    'key'     => 'moderation_blocked',
                    'message' => 'Your text contains disallowed terms. Please rephrase it.',
                    'fields'  => [],
                ],
            ]));
            return $response->withStatus(422)->withHeader('Content-Type', 'application/json');
        }

        $commentId = $this->commentRepo->create($ideaId, $userId, $rawBody);

        $this->audit->log('comment.created', ['board_id' => $boardId, 'idea_id' => $ideaId, 'comment_id' => $commentId]);

        $response->getBody()->write((string) json_encode(['ok' => true, 'id' => $commentId]));
        return $response->withStatus(201)->withHeader('Content-Type', 'application/json');
    }
}
