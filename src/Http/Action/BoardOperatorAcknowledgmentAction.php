<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Extension\BoardComplianceEnforcer;
use Votepit\Extension\BoardOperatorAcknowledgmentGate;
use Votepit\Http\Middleware\AccountContextMiddleware;
use Votepit\Http\Middleware\AuthNMiddleware;
use Votepit\Logging\AuditLogger;
use Votepit\Persistence\BoardRepository;

/**
 * GET/POST /admin/boards/{slug}/operator-acknowledgment (AuthZ: accountAdmin,
 * board-scoped, CSRF globally enforced for POST) — the ongoing counterpart to
 * BoardCreateAction's one-time acknowledgment check (board-operator-compliance
 * ticket 05).
 *
 * Ticket 05 only ever captured the acknowledgment at board-creation time; a
 * board created before that ticket shipped (or any board whose creation-time
 * write was otherwise skipped, e.g. a self-host install migrating onto
 * Cloud) has no row and no way to see or complete it anywhere in the admin
 * UI. This action closes that gap by exposing $gate->status() for display
 * and letting an admin retroactively acknowledge via the same
 * $gate->validate()/record() pair BoardCreateAction already uses — same
 * field convention (`operator_acknowledged`), same 422 `error.key` shape on
 * rejection.
 *
 * Idempotent by design: POST is a no-op (200, current status, no second
 * write) once a board is already acknowledged — record() is only ever
 * called once per board, matching the UNIQUE(board_id) constraint on
 * `board_operator_acknowledgements`.
 *
 * Community default (NullBoardOperatorAcknowledgmentGate): status() is
 * always null and validate() never rejects, so POST always "succeeds"
 * without persisting anything — self-host has no Cloud contract to
 * acknowledge, same as BoardCreateAction's behavior.
 */
final readonly class BoardOperatorAcknowledgmentAction
{
    public function __construct(
        private BoardRepository $boardRepo,
        private BoardOperatorAcknowledgmentGate $operatorAckGate,
        private AuditLogger $audit,
        private BoardComplianceEnforcer $complianceEnforcer,
    ) {}

    /** @param array<string, mixed> $args */
    public function getStatus(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $slug      = is_string($args['slug'] ?? null) ? $args['slug'] : '';
        $accountId = (int) $request->getAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID);
        $board     = $this->boardRepo->findBySlugForAccount($slug, $accountId);
        if (!is_array($board)) {
            return $this->notFound($response);
        }

        return $this->statusResponse($response, $this->operatorAckGate->status((int) $board['id']));
    }

    /** @param array<string, mixed> $args */
    public function acknowledge(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $slug      = is_string($args['slug'] ?? null) ? $args['slug'] : '';
        $accountId = (int) $request->getAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID);
        $board     = $this->boardRepo->findBySlugForAccount($slug, $accountId);
        if (!is_array($board)) {
            return $this->notFound($response);
        }

        $boardId = (int) $board['id'];
        $current = $this->operatorAckGate->status($boardId);
        if ($current !== null) {
            // Already acknowledged — idempotent no-op, never a second write
            // (UNIQUE(board_id) backstop).
            return $this->statusResponse($response, $current);
        }

        $parsed = $request->getParsedBody();
        $fields = is_array($parsed) ? $parsed : [];

        $ackError = $this->operatorAckGate->validate($fields);
        if ($ackError !== null) {
            $response->getBody()->write((string) json_encode([
                'error' => [
                    'key'     => $ackError,
                    'message' => 'You must acknowledge that you are this board\'s operator.',
                ],
            ]));
            return $response->withStatus(422)->withHeader('Content-Type', 'application/json');
        }

        $user   = $request->getAttribute(AuthNMiddleware::ATTR_USER);
        $userId = is_array($user) ? (int) ($user['id'] ?? 0) : 0;
        $this->operatorAckGate->record($boardId, $userId);
        $this->audit->log('board.operator_acknowledgment_recorded', ['board_id' => $boardId]);
        $this->complianceEnforcer->enforceForAccount($accountId);

        return $this->statusResponse($response, $this->operatorAckGate->status($boardId));
    }

    /** @param array{acknowledged_at: string, acknowledged_by_user_id: int}|null $status */
    private function statusResponse(ResponseInterface $response, ?array $status): ResponseInterface
    {
        $response->getBody()->write((string) json_encode([
            'acknowledged'            => $status !== null,
            'acknowledged_at'         => $status['acknowledged_at'] ?? null,
            'acknowledged_by_user_id' => $status['acknowledged_by_user_id'] ?? null,
        ]));
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }

    private function notFound(ResponseInterface $response): ResponseInterface
    {
        $response->getBody()->write((string) json_encode([
            'error' => ['key' => 'not_found', 'message' => 'Board not found.'],
        ]));
        return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
    }
}
