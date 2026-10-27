<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Domain\BoardPhases;
use Votepit\Domain\BoardStatus;
use Votepit\Http\Middleware\AccountContextMiddleware;
use Votepit\Http\Support\FrozenBoardGuard;
use Votepit\Http\Support\JsonError;
use Votepit\Logging\AuditLogger;
use Votepit\Persistence\BoardRepository;
use Votepit\Persistence\BoardStatusRepository;
use Votepit\Support\Clock;

/**
 * GET  /admin/boards/{slug}/phases — the board's optional deadlines plus the
 *                                     server-computed effective state.
 * POST /admin/boards/{slug}/phases — replaces all four bounds.
 *
 * Broaden-use-cases issue 04 (migrations/0082_add_board_phase_windows.sql).
 * Available on every plan (user decision 3b), not plan-gated.
 *
 * AuthZ: accountAdmin at route level (owner|admin; moderators only act on
 * single ideas). Board-scoped via findBySlugForAccount() — a foreign
 * account's board is unfindable → 404. CSRF globally enforced for POST.
 *
 * POST semantics: full replacement of the four fields — each is an ISO 8601
 * date-time WITH an explicit offset (BoardPhases::parseInstant()), stored
 * as UTC; a missing/empty/null field clears that bound ("clear" action).
 * opens < closes when both bounds of a phase are set; a closes value in the
 * past is allowed (the phase is then closed immediately). Any invalid field
 * → 422 validation_error, nothing written. Every successful save is
 * audit-logged (`board.phases_updated`, bounds only — no PII).
 *
 * The response carries `now` and the computed `phases`, plus the statuses
 * whose allow_votes flag closes voting regardless of the window
 * (`statuses_closed_for_votes`) — so the settings page can show the
 * EFFECTIVE state per phase (timer AND status flag) without trusting the
 * client clock.
 */
final readonly class BoardPhasesAdminAction
{
    public function __construct(
        private BoardRepository $boardRepo,
        private BoardStatusRepository $statusRepo,
        private AuditLogger $audit,
        private Clock $clock,
    ) {}

    /** @param array<string, mixed> $args */
    public function get(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $board = $this->board($request, $args);
        if ($board === null) {
            return JsonError::respond($response, 404, 'not_found', 'Board not found.');
        }

        return $this->json($response, $this->payload($board));
    }

    /** @param array<string, mixed> $args */
    public function post(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $board = $this->board($request, $args);
        if ($board === null) {
            return JsonError::respond($response, 404, 'not_found', 'Board not found.');
        }
        if (FrozenBoardGuard::isFrozen($board)) {
            return FrozenBoardGuard::reject($response);
        }

        $parsed = $request->getParsedBody();
        $input  = is_array($parsed) ? $parsed : [];

        /** @var array<string, ?\DateTimeImmutable> $instants */
        $instants = [];
        $fields   = [];
        foreach (BoardPhases::COLUMNS as $column) {
            $raw = $input[$column] ?? null;
            if ($raw === null || $raw === '') {
                $instants[$column] = null;
                continue;
            }
            $instant = is_string($raw) ? BoardPhases::parseInstant($raw) : null;
            if (!$instant instanceof \DateTimeImmutable) {
                $fields[$column] = 'Please enter a valid date and time.';
                continue;
            }
            $instants[$column] = $instant;
        }

        foreach (['submissions', 'voting'] as $phase) {
            $opens  = $instants[$phase . '_opens_at'] ?? null;
            $closes = $instants[$phase . '_closes_at'] ?? null;
            if ($opens !== null && $closes !== null && $opens >= $closes) {
                $fields[$phase . '_closes_at'] = 'The end must be after the start.';
            }
        }

        if ($fields !== []) {
            return JsonError::respond($response, 422, 'validation_error', 'Validation failed.', ['fields' => $fields]);
        }

        $bounds = [
            'submissions_opens_at'  => $instants['submissions_opens_at']?->format(BoardPhases::DB_FORMAT),
            'submissions_closes_at' => $instants['submissions_closes_at']?->format(BoardPhases::DB_FORMAT),
            'voting_opens_at'       => $instants['voting_opens_at']?->format(BoardPhases::DB_FORMAT),
            'voting_closes_at'      => $instants['voting_closes_at']?->format(BoardPhases::DB_FORMAT),
        ];
        $boardId   = (int) $board['id'];
        $accountId = (int) $board['account_id'];
        $this->boardRepo->updatePhaseWindows($boardId, $accountId, $bounds);

        $this->audit->log('board.phases_updated', ['board_id' => $boardId, ...$bounds]);

        return $this->json($response, $this->payload([...$board, ...$bounds]));
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>|null
     */
    private function board(ServerRequestInterface $request, array $args): ?array
    {
        $slug      = is_string($args['slug'] ?? null) ? $args['slug'] : '';
        $accountId = (int) $request->getAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID);

        return $this->boardRepo->findBySlugForAccount($slug, $accountId);
    }

    /**
     * @param array<string, mixed> $board
     * @return array<string, mixed>
     */
    private function payload(array $board): array
    {
        $now    = $this->clock->now();
        $phases = BoardPhases::fromBoard($board, $now)->toArray();

        $closedForVotes = array_values(array_map(
            static fn (BoardStatus $s): array => $s->toArray(),
            array_filter($this->statusRepo->registryFor($board)->all(), static fn (BoardStatus $s): bool => !$s->allowVotes),
        ));

        return [
            'submissions_opens_at'      => $phases['submissions']['opens_at'],
            'submissions_closes_at'     => $phases['submissions']['closes_at'],
            'voting_opens_at'           => $phases['voting']['opens_at'],
            'voting_closes_at'          => $phases['voting']['closes_at'],
            'phases'                    => $phases,
            'now'                       => $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            'statuses_closed_for_votes' => $closedForVotes,
        ];
    }

    /** @param array<string, mixed> $data */
    private function json(ResponseInterface $response, array $data): ResponseInterface
    {
        $response->getBody()->write((string) json_encode($data));

        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }
}
