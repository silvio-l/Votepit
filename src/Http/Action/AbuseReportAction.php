<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validation;
use Votepit\Http\Middleware\AuthNMiddleware;
use Votepit\Logging\AuditLogger;
use Votepit\Persistence\AbuseReportRepository;
use Votepit\Persistence\AccountRepository;
use Votepit\Persistence\BoardRepository;
use Votepit\Persistence\IdeaRepository;
use Votepit\Security\EncryptionService;

/**
 * POST /reports                        — public abuse-report intake.
 * GET  /operator/reports               — operator inbox.
 * POST /operator/reports/{id}/review   — mark a report reviewed/dismissed.
 *
 * submit() is the ONE unauthenticated (AuthZ: anon) route here —
 * the DSA Art. 16 reporting mechanism must be reachable by whoever is
 * concerned about content, not only logged-in members. It still goes through
 * the global CSRF gate exactly like POST /login (a single-use capability
 * isn't in play here, but the same anon-POST-with-cookie-CSRF pattern
 * applies — see CsrfMiddleware class doc).
 *
 * Resolution to account_id/board_id/idea_id is DELIBERATELY best-effort: the
 * report is stored even when the supplied slugs don't resolve to a real
 * account/board (a reporter may misremember/mistype a slug, or the content
 * may already be gone) — target_url (the raw string the reporter typed) is
 * always kept regardless. Because a report can concern ANY tenant, not just
 * the caller's own account context, board resolution goes through an
 * explicit account_slug (NOT AccountContextMiddleware::ATTR_ACCOUNT_ID, which
 * falls back to the default account for this unprefixed global route).
 *
 * list()/review() are operator-only (AuthZMiddleware::operator()) — every
 * mutation is audit-logged, tagged actor_tier=operator.
 */
final readonly class AbuseReportAction
{
    private const ALLOWED_REVIEW_STATUSES = ['reviewed', 'dismissed'];

    public function __construct(
        private AbuseReportRepository $reports,
        private AccountRepository $accounts,
        private BoardRepository $boards,
        private IdeaRepository $ideas,
        private EncryptionService $reportEncryption,
        private AuditLogger $audit,
    ) {}

    public function submit(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $parsed = $request->getParsedBody();
        $body   = is_array($parsed) ? $parsed : [];

        $targetUrl    = trim((string) ($body['url'] ?? ''));
        $reason       = trim((string) ($body['reason'] ?? ''));
        $accountSlug  = trim((string) ($body['account_slug'] ?? ''));
        $boardSlug    = trim((string) ($body['board_slug'] ?? ''));
        $ideaIdRaw    = $body['idea_id'] ?? null;
        $reporterMail = trim((string) ($body['reporter_email'] ?? ''));

        $validator = Validation::createValidator();

        $urlErrors = $validator->validate($targetUrl, [
            new Assert\NotBlank(message: 'Please enter the affected address.'),
            new Assert\Length(max: 512, maxMessage: 'The address must be at most {{ limit }} characters long.'),
        ]);
        $reasonErrors = $validator->validate($reason, [
            new Assert\NotBlank(message: 'Please describe the reason for the report.'),
            new Assert\Length(min: 10, max: 4000, minMessage: 'Please describe the reason in a bit more detail (at least {{ limit }} characters).', maxMessage: 'The reason must be at most {{ limit }} characters long.'),
        ]);

        /** @var array<string, string> $fields */
        $fields = [];
        foreach ($urlErrors as $e) {
            $fields['url'] = (string) $e->getMessage();
            break;
        }
        foreach ($reasonErrors as $e) {
            $fields['reason'] = (string) $e->getMessage();
            break;
        }

        if ($reporterMail !== '' && filter_var($reporterMail, FILTER_VALIDATE_EMAIL) === false) {
            $fields['reporter_email'] = 'Invalid email address.';
        }

        if ($fields !== []) {
            return $this->json($response, 422, [
                'error' => ['key' => 'validation_error', 'message' => 'Validation failed.', 'fields' => $fields],
            ]);
        }

        // Best-effort resolution — never blocks submission if it fails.
        $accountId = null;
        $boardId   = null;
        $ideaId    = null;

        if ($accountSlug !== '') {
            $account = $this->accounts->findBySlug($accountSlug);
            if (is_array($account)) {
                $accountId = (int) $account['id'];

                if ($boardSlug !== '') {
                    $board = $this->boards->findBySlugForAccount($boardSlug, $accountId);
                    if (is_array($board)) {
                        $boardId = (int) $board['id'];

                        if (is_numeric($ideaIdRaw)) {
                            $idea = $this->ideas->findInBoard($boardId, (int) $ideaIdRaw);
                            if (is_array($idea)) {
                                $ideaId = (int) $idea['id'];
                            }
                        }
                    }
                }
            }
        }

        $reporterEmailEnc = $reporterMail !== '' ? $this->reportEncryption->encrypt($reporterMail) : null;

        $reportId = $this->reports->create($targetUrl, $reason, $accountId, $boardId, $ideaId, $reporterEmailEnc);

        $this->audit->log('abuse_report.submitted', [
            'report_id'  => $reportId,
            'account_id' => $accountId,
            'board_id'   => $boardId,
            'idea_id'    => $ideaId,
        ]);

        return $this->json($response, 201, ['ok' => true, 'id' => $reportId]);
    }

    public function list(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $reports = array_map(
            $this->presentReport(...),
            $this->reports->listAll(),
        );

        return $this->json($response, 200, ['reports' => $reports]);
    }

    /** @param array<string, mixed> $args */
    public function review(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $reportId = is_numeric($args['id'] ?? null) ? (int) $args['id'] : 0;
        $report   = $reportId > 0 ? $this->reports->findById($reportId) : null;
        if (!is_array($report)) {
            return $this->json($response, 404, ['error' => ['key' => 'not_found', 'message' => 'Report not found.']]);
        }

        $parsed = $request->getParsedBody();
        $status = is_array($parsed) ? (string) ($parsed['status'] ?? '') : '';

        if (!in_array($status, self::ALLOWED_REVIEW_STATUSES, true)) {
            return $this->json($response, 422, [
                'error' => ['key' => 'invalid_input', 'message' => 'status must be "reviewed" or "dismissed".'],
            ]);
        }

        $actorId = $this->actorId($request);
        $this->reports->markReviewed($reportId, $status, $actorId);

        $this->audit->log('operator.report.reviewed', [
            'actor_tier' => 'operator',
            'actor_id'   => $actorId,
            'report_id'  => $reportId,
            'status'     => $status,
        ]);

        return $this->json($response, 200, ['ok' => true, 'status' => $status]);
    }

    /**
     * @param  array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function presentReport(array $row): array
    {
        $reporterEmail = null;
        $enc           = $row['reporter_email_enc'] ?? null;
        if (is_string($enc) && $enc !== '') {
            $reporterEmail = $this->reportEncryption->decrypt($enc);
        }

        return [
            'id'             => (int) $row['id'],
            'account_id'     => $row['account_id'] !== null ? (int) $row['account_id'] : null,
            'board_id'       => $row['board_id'] !== null ? (int) $row['board_id'] : null,
            'idea_id'        => $row['idea_id'] !== null ? (int) $row['idea_id'] : null,
            'target_url'     => (string) $row['target_url'],
            'reason'         => (string) $row['reason'],
            'reporter_email' => $reporterEmail,
            'status'         => (string) $row['status'],
            'reviewed_by'    => $row['reviewed_by'] !== null ? (int) $row['reviewed_by'] : null,
            'reviewed_at'    => $row['reviewed_at'],
            'created_at'     => $row['created_at'],
        ];
    }

    private function actorId(ServerRequestInterface $request): int
    {
        /** @var array<string, mixed>|null $actor */
        $actor = $request->getAttribute(AuthNMiddleware::ATTR_USER);
        return is_array($actor) ? (int) ($actor['id'] ?? 0) : 0;
    }

    /** @param array<string, mixed> $payload */
    private function json(ResponseInterface $response, int $status, array $payload): ResponseInterface
    {
        $response->getBody()->write((string) json_encode($payload));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
