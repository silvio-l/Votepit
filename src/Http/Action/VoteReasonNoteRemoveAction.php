<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Domain\VoteReason;
use Votepit\Http\Middleware\AccountContextMiddleware;
use Votepit\Http\Middleware\AuthNMiddleware;
use Votepit\Http\Support\JsonError;
use Votepit\Logging\AuditLogger;
use Votepit\Persistence\BoardRepository;
use Votepit\Persistence\IdeaRepository;
use Votepit\Persistence\VoteReasonRepository;

/**
 * POST /admin/boards/{slug}/ideas/{id}/vote-reasons/note/remove — moderator
 * takedown of ONE free-text reason (owner answer 22a, P-03). The reason choice
 * stays; only the text is cleared. The vote itself is never changed.
 *
 * The note is addressed by reason and exact text (body: { reason, note }), the
 * same values the operator aggregate returns, so no identifier is exposed.
 * AuthZ: accountModerate at route level (owner|admin|moderator). Board and
 * idea are loaded account- and board-scoped (foreign → 404). Frozen boards
 * stay open for this takedown (owner answer 26a), see BoardWriteRoutePolicy.
 *
 * Errors: 404 not_found (board, idea or text), 422 validation_error (reason
 * outside the fixed choices, empty or over-long text). Success: 200 { ok: true }.
 * The audit log records board, idea, reason and actor only, never the text.
 */
final readonly class VoteReasonNoteRemoveAction
{
    public function __construct(
        private BoardRepository $boardRepo,
        private IdeaRepository $ideaRepo,
        private VoteReasonRepository $reasonRepo,
        private AuditLogger $audit,
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

        $parsed = $request->getParsedBody();
        $body   = is_array($parsed) ? $parsed : [];
        $reason = is_string($body['reason'] ?? null) ? $body['reason'] : '';
        $note   = is_string($body['note'] ?? null) ? trim($body['note']) : '';

        if (!VoteReason::isChoice($reason)) {
            return JsonError::respond($response, 422, 'validation_error', 'Invalid reason.');
        }
        if ($note === '' || mb_strlen($note, 'UTF-8') > VoteReason::NOTE_MAX_LENGTH) {
            return JsonError::respond($response, 422, 'validation_error', 'Invalid note.');
        }

        if (!$this->reasonRepo->clearNoteForIdea($ideaId, $reason, $note)) {
            return JsonError::respond($response, 404, 'not_found', 'Reason text not found.');
        }

        /** @var array<string, mixed>|null $user */
        $user    = $request->getAttribute(AuthNMiddleware::ATTR_USER);
        $actorId = is_array($user) ? (int) ($user['id'] ?? 0) : 0;
        $this->audit->log('vote_reason.note_removed', [
            'board_id' => $boardId,
            'idea_id'  => $ideaId,
            'reason'   => $reason,
            'actor_id' => $actorId,
        ]);

        $response->getBody()->write((string) json_encode(['ok' => true]));

        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }
}
