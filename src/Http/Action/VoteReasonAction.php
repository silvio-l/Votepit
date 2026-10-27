<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Domain\BoardPhases;
use Votepit\Domain\VoteReason;
use Votepit\Http\Middleware\AccountContextMiddleware;
use Votepit\Http\Middleware\AuthNMiddleware;
use Votepit\Http\Support\FrozenBoardGuard;
use Votepit\Http\Support\JsonError;
use Votepit\Logging\AuditLogger;
use Votepit\Persistence\BlockRepository;
use Votepit\Persistence\BoardRepository;
use Votepit\Persistence\BoardStatusRepository;
use Votepit\Persistence\IdeaRepository;
use Votepit\Persistence\VoteReasonRepository;
use Votepit\Persistence\VoteRepository;
use Votepit\Security\AnonymousVoteCookie;
use Votepit\Security\AnonymousVoterHasher;
use Votepit\Support\Clock;

/**
 * POST /{board}/ideas/{id}/vote/reason — the optional reason for a DOWN vote
 * (P-03, migration 0099). Set or replace; the vote itself is never changed.
 *
 * Identity is decided exactly like VoteAction: a signed-in user, or an
 * anonymous voter whose cookie resolves to a token hash (or to the linked
 * user, ADR 0004) on a board that opted into anonymous voting. Anything else
 * → 401. The reason can only attach to the caller's OWN down vote on THIS
 * idea (VoteRepository::findDownVoteId), so no foreign or up vote is touched.
 *
 * Gates in order: board (404, foreign account included), frozen board (423),
 * idea (404), board opt-in (409 vote_reasons_disabled), voting window and
 * status (423, same keys as VoteAction), identity (401, 403 when blocked),
 * input (422 invalid_reason / invalid_note / note_too_long), a down vote
 * (409 no_down_vote). Only then one write.
 *
 * Errors: 401 login_required, 403 blocked, 404 not_found, 409
 * vote_reasons_disabled / no_down_vote, 422 invalid_reason / invalid_note /
 * note_too_long, 423 board_frozen / voting_closed / voting_not_open /
 * status_closed_for_votes. Success: 200 { ok, reason, note }.
 *
 * The note is plain text, stored as given (trimmed), never rendered as HTML.
 * The audit log records board, idea and reason only, never the note or identity.
 */
final readonly class VoteReasonAction
{
    public function __construct(
        private BoardRepository $boardRepo,
        private IdeaRepository $ideaRepo,
        private VoteRepository $voteRepo,
        private VoteReasonRepository $reasonRepo,
        private BlockRepository $blockRepo,
        private BoardStatusRepository $statusRepo,
        private AnonymousVoteCookie $anonCookie,
        private AnonymousVoterHasher $voterHasher,
        private AuditLogger $audit,
        private Clock $clock,
        // Installation kill switch (Config::$voteReasonsEnabled): while off, reasons
        // are refused exactly like a board with the flag off.
        private bool $installationAllowsVoteReasons = true,
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
            return JsonError::respond($response, 404, 'not_found', 'Board not found.');
        }

        if (FrozenBoardGuard::isFrozen($board)) {
            return FrozenBoardGuard::reject($response);
        }

        $boardId = (int) $board['id'];
        $ideaId  = (int) ($args['id'] ?? 0);
        $idea    = $this->ideaRepo->findInBoard($boardId, $ideaId);
        if (!is_array($idea)) {
            return JsonError::respond($response, 404, 'not_found', 'Idea not found.');
        }

        if (!$this->installationAllowsVoteReasons || !(bool) ($board['vote_reasons_enabled'] ?? false)) {
            return JsonError::respond($response, 409, 'vote_reasons_disabled', 'Reasons for votes are not enabled on this board.');
        }

        $votingRejection = BoardPhases::fromBoard($board, $this->clock->now())->votingRejection();
        if ($votingRejection !== null) {
            return JsonError::respond($response, 423, $votingRejection, $votingRejection === 'voting_closed'
                ? 'Voting on this board has closed.'
                : 'Voting on this board has not opened yet.');
        }

        if ($this->statusRepo->registryFor($board)->find((string) $idea['status_key'])?->allowVotes === false) {
            return JsonError::respond($response, 423, 'status_closed_for_votes', 'Voting is closed for ideas in this status.');
        }

        $identity = $this->resolveIdentity($request, $board, $boardId, $accountId);
        if ($identity === null) {
            return JsonError::respond($response, 401, 'login_required', 'Please sign in to vote.');
        }
        if ($identity === 'blocked') {
            return JsonError::respond($response, 403, 'blocked', 'You are blocked from this board.');
        }

        $parsed = $request->getParsedBody();
        $reason = is_array($parsed) ? ($parsed['reason'] ?? null) : null;
        if (!VoteReason::isChoice($reason)) {
            return JsonError::respond($response, 422, 'invalid_reason', 'Please choose one of the given reasons.');
        }
        try {
            $note = VoteReason::normalizeNote(is_array($parsed) ? ($parsed['note'] ?? null) : null);
        } catch (\InvalidArgumentException $e) {
            $key = $e->getMessage() === 'note_too_long' ? 'note_too_long' : 'invalid_note';

            return JsonError::respond($response, 422, $key, 'The note is too long or contains unsupported characters.');
        }

        $voteId = $this->voteRepo->findDownVoteId($ideaId, $identity['value'], $identity['anonymous']);
        if ($voteId === null) {
            return JsonError::respond($response, 409, 'no_down_vote', 'Reasons can only be added to a vote against.');
        }

        $this->reasonRepo->upsert($voteId, (string) $reason, $note);
        $this->audit->log('vote.reason_set', [
            'board_id' => $boardId,
            'idea_id'  => $ideaId,
            'reason'   => (string) $reason,
        ]);

        $response->getBody()->write((string) json_encode(['ok' => true, 'reason' => (string) $reason, 'note' => $note]));

        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }

    /**
     * @param array<string, mixed> $board
     * @return array{value: int|string, anonymous: bool}|'blocked'|null null = no identity (401)
     */
    private function resolveIdentity(ServerRequestInterface $request, array $board, int $boardId, int $accountId): array|string|null
    {
        /** @var array<string, mixed>|null $user */
        $user = $request->getAttribute(AuthNMiddleware::ATTR_USER);
        if (is_array($user)) {
            $userId = (int) ($user['id'] ?? 0);
            if ($this->blockRepo->isBlocked($accountId, $userId, $boardId)) {
                return 'blocked';
            }

            return ['value' => $userId, 'anonymous' => false];
        }

        if (!(bool) ($board['anonymous_voting_enabled'] ?? false)) {
            return null;
        }
        $rawToken = $this->anonCookie->read($request);
        if ($rawToken === null) {
            return null;
        }
        $tokenHash    = $this->voterHasher->hashToken($rawToken);
        $linkedUserId = $this->voteRepo->findLinkedUserId($tokenHash);
        if ($linkedUserId !== null) {
            if ($this->blockRepo->isBlocked($accountId, $linkedUserId, $boardId)) {
                return 'blocked';
            }

            return ['value' => $linkedUserId, 'anonymous' => false];
        }

        return ['value' => $tokenHash, 'anonymous' => true];
    }
}
