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
use Votepit\Persistence\BoardRepository;
use Votepit\Persistence\CommentRepository;
use Votepit\Persistence\IdeaRepository;
use Votepit\Persistence\ModerationConfigRepository;

/**
 * POST /{board}/ideas/{id}/comments/{commentId}/edit — the comment's own
 * author edits its body, only within a short window after posting (typo
 * fixes, not a way to rewrite a comment after the fact).
 *
 * AuthZ: user (anon → 401 JSON, via AuthZMiddleware::user() in AppFactory).
 * CSRF: globally enforced (CsrfMiddleware in the POST path).
 *
 * Structurally board-/idea-scoped: the idea is loaded board-scoped via
 * IdeaRepository::findInBoard() and the comment idea-scoped via
 * CommentRepository::findForIdea() — a comment of a foreign idea/board is
 * never addressable (404, no mutation), same pattern as
 * CommentModerationAction. Ownership (comment.author_id === requesting
 * user) is enforced here — not the boardAdmin/moderation path.
 *
 * Edit window: enforced server-side against the comment's ORIGINAL
 * created_at (not edited_at — the window doesn't reset on edit, otherwise
 * an author could keep the window open indefinitely). Expired → 422
 * `edit_window_expired`, distinct from ordinary validation errors so the
 * frontend can show a clear "too late to edit" message instead of a
 * field error.
 *
 * Same body validation + word-filter moderation as CommentCreateAction.
 */
final readonly class CommentUpdateAction
{
    /** Matches the "first 60 seconds" window the user asked for. */
    public const EDIT_WINDOW_SECONDS = 60;

    public function __construct(
        private BoardRepository $boardRepo,
        private IdeaRepository $ideaRepo,
        private CommentRepository $commentRepo,
        private AuditLogger $audit,
        private ContentModerationService $moderation,
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

        $boardId   = (int) $board['id'];
        $ideaId    = (int) ($args['id'] ?? 0);
        $commentId = (int) ($args['commentId'] ?? 0);

        $idea = $this->ideaRepo->findInBoard($boardId, $ideaId);
        if (!is_array($idea)) {
            $response->getBody()->write((string) json_encode([
                'error' => ['key' => 'not_found', 'message' => 'Idea not found.'],
            ]));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        $comment = $this->commentRepo->findForIdea($ideaId, $commentId);
        if (!is_array($comment)) {
            $response->getBody()->write((string) json_encode([
                'error' => ['key' => 'not_found', 'message' => 'Comment not found.'],
            ]));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        /** @var array<string, mixed> $user */
        $user   = $request->getAttribute(AuthNMiddleware::ATTR_USER);
        $userId = (int) ($user['id'] ?? 0);

        if ((int) ($comment['author_id'] ?? -1) !== $userId) {
            $response->getBody()->write((string) json_encode([
                'error' => ['key' => 'forbidden', 'message' => 'Access denied.'],
            ]));
            return $response->withStatus(403)->withHeader('Content-Type', 'application/json');
        }

        $createdAt = new \DateTimeImmutable((string) $comment['created_at']);
        $elapsed   = (new \DateTimeImmutable())->getTimestamp() - $createdAt->getTimestamp();
        if ($elapsed > self::EDIT_WINDOW_SECONDS) {
            $response->getBody()->write((string) json_encode([
                'error' => [
                    'key'     => 'edit_window_expired',
                    'message' => 'This comment can no longer be edited.',
                ],
            ]));
            return $response->withStatus(422)->withHeader('Content-Type', 'application/json');
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

        $this->commentRepo->update($ideaId, $commentId, $userId, $rawBody);

        $this->audit->log('comment.edited', ['board_id' => $boardId, 'idea_id' => $ideaId, 'comment_id' => $commentId]);

        $response->getBody()->write((string) json_encode(['ok' => true]));
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }
}
