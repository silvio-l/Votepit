<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validation;
use Votepit\Http\Middleware\AccountContextMiddleware;
use Votepit\Http\Middleware\AuthNMiddleware;
use Votepit\Logging\AuditLogger;
use Votepit\Persistence\CommentRepository;
use Votepit\Persistence\IdeaRepository;
use Votepit\Persistence\ModerationAppealRepository;
use Votepit\Persistence\ModerationRepository;

/**
 * POST  {accountPrefix}/appeals                       — the removed content's
 *       author submits an appeal against a moderation action.
 * GET   {accountPrefix}/moderation/appeals             — moderator/admin
 *       review inbox, scoped to the caller's own account.
 * POST  {accountPrefix}/moderation/appeals/{id}/resolve — moderator/admin
 *       resolves an appeal ('upheld' or 'overturned').
 *
 * board-operator-compliance issue 09 — the appeal path against issue 06/07's
 * content_moderation_actions rows. Structurally the account-scoped
 * counterpart of AbuseReportAction's global /reports inbox: same
 * submit/list/resolve shape, but appeals are inherently account-scoped
 * (an appeal always targets one specific account's moderation action,
 * unlike an anonymous, best-effort-resolved abuse report) and require an
 * authenticated submitter (the content's actual author), so this lives
 * under $accountPrefix like every other authenticated account route
 * instead of AbuseReportAction's bare global path.
 *
 * submit() AuthZ: `user` (any authenticated user reaches the action; the
 * check that the caller is ACTUALLY the original author of the removed
 * content happens inside — a non-author gets 403). list()/resolve() AuthZ:
 * `accountModerate` (owner|admin|moderator).
 *
 * Tenant isolation: a moderation_action_id belonging to a foreign account
 * (submit()), or an appeal id whose moderation action belongs to a foreign
 * account (resolve()), is a 404 — never 403 — consistent with the "invisible
 * = not found" convention used everywhere else in this codebase (does not
 * reveal whether the ID exists at all).
 *
 * "One open appeal per moderation action" (PRD) is enforced here via
 * ModerationAppealRepository::hasPendingAppeal() immediately before insert
 * — a second attempt while the first is still 'pending' gets 409.
 *
 * CSRF: globally enforced on every mutating route in this pipeline (see
 * AppFactory's CsrfMiddleware placement) — nothing extra needed here.
 */
final readonly class ModerationAppealAction
{
    private const ALLOWED_RESOLVE_STATUSES = ['upheld', 'overturned'];

    private const MAX_MESSAGE_LENGTH = 4000;

    public function __construct(
        private ModerationRepository $moderationRepo,
        private ModerationAppealRepository $appealsRepo,
        private IdeaRepository $ideaRepo,
        private CommentRepository $commentRepo,
        private AuditLogger $audit,
    ) {}

    public function submit(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $accountId = (int) $request->getAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID);

        $parsed = $request->getParsedBody();
        $body   = is_array($parsed) ? $parsed : [];

        $moderationActionId = is_numeric($body['moderation_action_id'] ?? null) ? (int) $body['moderation_action_id'] : 0;
        $message             = trim((string) ($body['message'] ?? ''));

        $moderationAction = $moderationActionId > 0 ? $this->moderationRepo->findById($moderationActionId) : null;
        if (!is_array($moderationAction) || (int) $moderationAction['account_id'] !== $accountId) {
            return $this->json($response, 404, [
                'error' => ['key' => 'not_found', 'message' => 'Moderation action not found.'],
            ]);
        }

        $author = $this->findEntityAuthor((string) $moderationAction['entity_type'], (int) $moderationAction['entity_id']);
        if ($author === null) {
            return $this->json($response, 404, [
                'error' => ['key' => 'not_found', 'message' => 'Moderation action not found.'],
            ]);
        }

        $actorId = $this->actorId($request);
        if ($actorId === 0 || $actorId !== $author) {
            return $this->json($response, 403, [
                'error' => ['key' => 'forbidden', 'message' => 'Only the original author can appeal this removal.'],
            ]);
        }

        $validator = Validation::createValidator();
        $errors    = $validator->validate($message, [
            new Assert\NotBlank(message: 'Please describe why you are appealing this removal.'),
            new Assert\Length(min: 10, max: self::MAX_MESSAGE_LENGTH, minMessage: 'Please describe your appeal in a bit more detail (at least {{ limit }} characters).', maxMessage: 'The message must be at most {{ limit }} characters long.'),
        ]);

        /** @var array<string, string> $fields */
        $fields = [];
        foreach ($errors as $e) {
            $fields['message'] = (string) $e->getMessage();
            break;
        }
        if ($fields !== []) {
            return $this->json($response, 422, [
                'error' => ['key' => 'validation_error', 'message' => 'Validation failed.', 'fields' => $fields],
            ]);
        }

        if ($this->appealsRepo->hasPendingAppeal($moderationActionId)) {
            return $this->json($response, 409, [
                'error' => ['key' => 'appeal_already_pending', 'message' => 'An appeal for this removal is already pending review.'],
            ]);
        }

        $appealId = $this->appealsRepo->submit($moderationActionId, $actorId, $message);

        $this->audit->log('moderation.appeal_submitted', [
            'appeal_id'             => $appealId,
            'moderation_action_id'  => $moderationActionId,
            'account_id'            => $accountId,
            'actor_id'              => $actorId,
        ]);

        return $this->json($response, 201, ['ok' => true, 'id' => $appealId]);
    }

    public function list(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $accountId = (int) $request->getAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID);

        $appeals = array_map($this->presentAppeal(...), $this->appealsRepo->findForAccount($accountId));

        return $this->json($response, 200, ['appeals' => $appeals]);
    }

    /** @param array<string, mixed> $args */
    public function resolve(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $accountId = (int) $request->getAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID);
        $appealId  = is_numeric($args['id'] ?? null) ? (int) $args['id'] : 0;

        $appeal = $appealId > 0 ? $this->appealsRepo->findById($appealId) : null;
        if (!is_array($appeal)) {
            return $this->json($response, 404, [
                'error' => ['key' => 'not_found', 'message' => 'Appeal not found.'],
            ]);
        }

        $moderationAction = $this->moderationRepo->findById((int) $appeal['moderation_action_id']);
        if (!is_array($moderationAction) || (int) $moderationAction['account_id'] !== $accountId) {
            return $this->json($response, 404, [
                'error' => ['key' => 'not_found', 'message' => 'Appeal not found.'],
            ]);
        }

        $parsed = $request->getParsedBody();
        $status = is_array($parsed) ? (string) ($parsed['status'] ?? '') : '';

        if (!in_array($status, self::ALLOWED_RESOLVE_STATUSES, true)) {
            return $this->json($response, 422, [
                'error' => ['key' => 'invalid_input', 'message' => 'status must be "upheld" or "overturned".'],
            ]);
        }

        $actorId = $this->actorId($request);
        $this->appealsRepo->resolve($appealId, $status, $actorId);

        $this->audit->log('moderation.appeal_resolved', [
            'appeal_id'  => $appealId,
            'account_id' => $accountId,
            'actor_id'   => $actorId,
            'status'     => $status,
        ]);

        return $this->json($response, 200, ['ok' => true, 'status' => $status]);
    }

    /**
     * Resolves the author of the content a moderation action targeted —
     * including already-soft-deleted content (that's the whole point: an
     * appeal is only ever filed against content that has already been
     * removed). Null if the entity itself somehow no longer exists (should
     * be unreachable given content_moderation_actions rows are never
     * deleted while the entity is only hard-deleted by the purge job much
     * later, but handled defensively rather than assumed).
     */
    private function findEntityAuthor(string $entityType, int $entityId): ?int
    {
        $entity = match ($entityType) {
            'idea'    => $this->ideaRepo->findByIdIncludingDeleted($entityId),
            'comment' => $this->commentRepo->findByIdIncludingDeleted($entityId),
            default   => null,
        };

        return is_array($entity) ? (int) $entity['author_id'] : null;
    }

    /**
     * @param  array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function presentAppeal(array $row): array
    {
        return [
            'id'                    => (int) $row['id'],
            'moderation_action_id'  => (int) $row['moderation_action_id'],
            'submitted_by_user_id'  => (int) $row['submitted_by_user_id'],
            'message'               => (string) $row['message'],
            'status'                => (string) $row['status'],
            'resolved_by_user_id'   => $row['resolved_by_user_id'] !== null ? (int) $row['resolved_by_user_id'] : null,
            'resolved_at'           => $row['resolved_at'],
            'created_at'            => $row['created_at'],
            'entity_type'           => (string) $row['entity_type'],
            'entity_id'             => (int) $row['entity_id'],
            'board_id'              => (int) $row['board_id'],
            'reason_code'           => (string) $row['reason_code'],
            'content_snapshot'      => (string) $row['content_snapshot'],
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
