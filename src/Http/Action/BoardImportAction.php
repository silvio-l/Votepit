<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Http\Middleware\AccountContextMiddleware;
use Votepit\Http\Middleware\AuthNMiddleware;
use Votepit\Http\Support\FrozenBoardGuard;
use Votepit\Import\BoardImportService;
use Votepit\Import\ImportAdapter;
use Votepit\Import\ImportFileException;
use Votepit\Logging\AuditLogger;
use Votepit\Persistence\BoardRepository;

/**
 * POST /admin/boards/{slug}/import — import ideas from a CSV, Canny or
 * Featurebase export into one board (issue cp-16).
 *
 * AuthZ: accountAdmin (route), CSRF global, per-user rate limit
 * `board:import`. The board is resolved via findBySlugForAccount() — a slug
 * of another account is a 404, never a write (account scoping). Frozen
 * board → FrozenBoardGuard rejection, same as every other board write.
 *
 * Board deadlines (migration 0082): an import is an admin bulk path, not a
 * submission — it is deliberately NOT bound by the submission/voting
 * windows (same as owner/admin seeding ideas in the app).
 *
 * Plan gate: none (product decision 1a — import on all tiers and self-host).
 * The PlanPolicy has no idea or voter caps an import could exceed: imported
 * vote counts are a per-idea baseline, not voters, so no mail-bound voter
 * limit is touched. The per-request limits (CsvTable::MAX_BYTES/MAX_ROWS,
 * BoardImportService::MAX_NEW_TAGS) apply to every plan alike.
 *
 * JSON body:
 *   format           'csv' | 'canny' | 'featurebase' (required, allow-list)
 *   content          the file's text (required, ≤ CsvTable::MAX_BYTES)
 *   dry_run          bool, DEFAULTS TO TRUE — only an explicit `false`
 *                    writes anything (fail-safe)
 *   idempotency_key  [A-Za-z0-9_-]{8,64}, required when dry_run is false
 *
 * Responses: 200 { ok, dry_run, report } (dry run, applied or replayed —
 * `report.result` tells which), 409 on an idempotency-key conflict, 422 on
 * invalid input or an unusable file (`error.reason`, closed vocabulary).
 * The report never contains author data: it is not part of the import model.
 */
final readonly class BoardImportAction
{
    private const KEY_PATTERN = '/^[A-Za-z0-9_-]{8,64}$/';

    public function __construct(
        private BoardRepository $boardRepo,
        private BoardImportService $importService,
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
            return $this->json($response, 404, ['error' => ['key' => 'not_found', 'message' => 'Board not found.']]);
        }

        if (FrozenBoardGuard::isFrozen($board)) {
            return FrozenBoardGuard::reject($response);
        }

        $parsed = $request->getParsedBody();
        $fields = is_array($parsed) ? $parsed : [];

        $adapter = ImportAdapter::forFormat(is_string($fields['format'] ?? null) ? $fields['format'] : '');
        $content = $fields['content'] ?? null;
        $dryRun  = ($fields['dry_run'] ?? true) !== false;
        $key     = $fields['idempotency_key'] ?? null;

        $errors = [];
        if (!$adapter instanceof \Votepit\Import\ImportAdapter) {
            $errors['format'] = 'Unknown import format.';
        }
        if (!is_string($content)) {
            $errors['content'] = 'The file content is missing.';
        }
        if (!$dryRun && (!is_string($key) || preg_match(self::KEY_PATTERN, $key) !== 1)) {
            $errors['idempotency_key'] = 'A valid idempotency key is required.';
        }
        if ($errors !== []) {
            return $this->json($response, 422, ['error' => ['key' => 'validation_error', 'message' => 'Validation failed.', 'fields' => $errors]]);
        }

        /** @var array<string, mixed>|null $user */
        $user    = $request->getAttribute(AuthNMiddleware::ATTR_USER);
        $actorId = is_array($user) ? (int) ($user['id'] ?? 0) : 0;
        $boardId = (int) $board['id'];

        try {
            $report = $this->importService->run(
                $boardId,
                $actorId,
                $adapter,
                $content,
                $dryRun,
                is_string($key) ? $key : null,
                new \DateTimeImmutable(),
            );
        } catch (ImportFileException $e) {
            return $this->json($response, 422, ['error' => ['key' => 'import_invalid', 'reason' => $e->reason, 'message' => 'The file cannot be imported.']]);
        }

        if ($report['result'] === BoardImportService::RESULT_CONFLICT) {
            return $this->json($response, 409, ['error' => ['key' => 'import_conflict', 'message' => 'This import key was already used for a different file.']]);
        }

        if (!$dryRun && $report['result'] === BoardImportService::RESULT_OK) {
            $this->audit->log('board.import', [
                'board_id'  => $boardId,
                'import_id' => $report['import_id'],
                'format'    => $adapter->format,
                'imported'  => $report['importable'],
                'skipped'   => $report['skipped'],
                'actor_id'  => $actorId,
            ]);
        }

        return $this->json($response, 200, ['ok' => true, 'dry_run' => $dryRun, 'report' => $report]);
    }

    /** @param array<string, mixed> $payload */
    private function json(ResponseInterface $response, int $status, array $payload): ResponseInterface
    {
        $response->getBody()->write((string) json_encode($payload));

        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
