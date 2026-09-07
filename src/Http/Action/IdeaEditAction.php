<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validation;
use Votepit\Domain\ContentModerationService;
use Votepit\Domain\TitleNormalizer;
use Votepit\Http\Middleware\AccountContextMiddleware;
use Votepit\Http\Middleware\AuthNMiddleware;
use Votepit\Http\Support\FrozenBoardGuard;
use Votepit\Logging\AuditLogger;
use Votepit\Persistence\BlockRepository;
use Votepit\Persistence\BoardRepository;
use Votepit\Persistence\IdeaRepository;
use Votepit\Persistence\ModerationConfigRepository;
use Votepit\Security\TimeTrapService;

/**
 * GET /{board}/ideas/{id}/edit  — edit form (AuthZ: anon + ownership, anon → 401 JSON).
 * POST /{board}/ideas/{id}      — update an idea (AuthZ: user + ownership, CSRF enforced).
 *
 * Ownership check in the action (not in the pipeline guard):
 *   - idea not in the board → 404
 *   - idea present but different author → 403
 *   - anonymous → 401 JSON (in-action, no more PRG redirect)
 *
 * Moderation + bot defense: same contract as IdeaCreateAction.
 * Honeypot + time trap active regardless of the board toggle.
 * Word filter: only when the board toggle is on (same as on submit).
 *
 * Board-scoped user block — a thin inline guard in postEdit() (no central
 * middleware), the board is already loaded at this point. Runs
 * additively to the accountwide check (BlockCheckMiddleware, already run
 * before the action).
 *
 * Edit window: same rationale/shape as CommentUpdateAction — enforced
 * server-side against the idea's ORIGINAL created_at (not updated_at, so
 * an edit never resets the window), gives typo-fix time without turning
 * an idea into something an author can silently rewrite indefinitely
 * (fail-secure content integrity), keeps the idea's identity/write-load
 * stable for downstream consumers (search/notification/translation
 * caches don't need to keep re-processing an idea forever), and bounds
 * future machine-translation cost to a one-time job per idea rather than
 * a recurring one. Expired → 422 `edit_window_expired` on BOTH the GET
 * (so the SPA can show a clear "too late" state instead of a live form)
 * and the POST (defense in depth against a direct request after the GET
 * was loaded within the window but the POST arrives after it closes).
 */
final readonly class IdeaEditAction
{
    /** Idea edit window: two hours after the idea's original created_at. */
    public const EDIT_WINDOW_SECONDS = 7200;

    /** Honeypot form field name. */
    public const HONEYPOT_FIELD = 'website';

    /** Time-Trap form field name. */
    public const TIME_TRAP_FIELD = '_form_at';

    public function __construct(
        private BoardRepository $boardRepo,
        private IdeaRepository $ideaRepo,
        private TitleNormalizer $normalizer,
        private AuditLogger $audit,
        private ContentModerationService $moderation,
        private TimeTrapService $timeTrap,
        private BlockRepository $blockRepo,
        private ?ModerationConfigRepository $moderationConfigRepo = null,
    ) {}

    // -------------------------------------------------------------------------
    // GET /{board}/ideas/{id}/edit
    // -------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $args
     */
    public function getEdit(
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

        $ideaId = (int) ($args['id'] ?? 0);
        $idea   = $this->ideaRepo->findInBoard((int) $board['id'], $ideaId);
        if (!is_array($idea)) {
            $response->getBody()->write((string) json_encode([
                'error' => ['key' => 'not_found', 'message' => 'Idea not found.'],
            ]));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        // Anon → 401 JSON (the SPA redirects to login).
        $user = $request->getAttribute(AuthNMiddleware::ATTR_USER);
        if (!is_array($user)) {
            $response->getBody()->write((string) json_encode([
                'error' => ['key' => 'unauthenticated', 'message' => 'Login required.'],
            ]));
            return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
        }

        if ((int) ($idea['author_id'] ?? -1) !== (int) ($user['id'] ?? 0)) {
            $response->getBody()->write((string) json_encode([
                'error' => ['key' => 'forbidden', 'message' => 'Access denied.'],
            ]));
            return $response->withStatus(403)->withHeader('Content-Type', 'application/json');
        }

        if ($this->editWindowExpired($idea)) {
            $response->getBody()->write((string) json_encode([
                'error' => [
                    'key'     => 'edit_window_expired',
                    'message' => 'This idea can no longer be edited.',
                ],
            ]));
            return $response->withStatus(422)->withHeader('Content-Type', 'application/json');
        }

        $response->getBody()->write((string) json_encode([
            'board'            => [
                'id'   => (int) $board['id'],
                'slug' => $slug,
                'name' => is_string($board['name'] ?? null) ? $board['name'] : $slug,
            ],
            'idea'             => $idea,
            'is_authenticated' => true,
            'form_at'          => $this->timeTrap->stamp(),
        ]));
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }

    /** @param array<string, mixed> $idea */
    private function editWindowExpired(array $idea): bool
    {
        $createdAt = new \DateTimeImmutable((string) $idea['created_at']);
        $elapsed   = (new \DateTimeImmutable())->getTimestamp() - $createdAt->getTimestamp();

        return $elapsed > self::EDIT_WINDOW_SECONDS;
    }

    // -------------------------------------------------------------------------
    // POST /{board}/ideas/{id}
    // -------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $args
     */
    public function postEdit(
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

        $ideaId = (int) ($args['id'] ?? 0);
        $idea   = $this->ideaRepo->findInBoard((int) $board['id'], $ideaId);
        if (!is_array($idea)) {
            $response->getBody()->write((string) json_encode([
                'error' => ['key' => 'not_found', 'message' => 'Idea not found.'],
            ]));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        /** @var array<string, mixed> $user */
        $user = $request->getAttribute(AuthNMiddleware::ATTR_USER);
        if ((int) ($idea['author_id'] ?? -1) !== (int) ($user['id'] ?? 0)) {
            $response->getBody()->write((string) json_encode([
                'error' => ['key' => 'forbidden', 'message' => 'Access denied.'],
            ]));
            return $response->withStatus(403)->withHeader('Content-Type', 'application/json');
        }

        if ($this->blockRepo->isBlocked($accountId, (int) ($user['id'] ?? 0), (int) $board['id'])) {
            $response->getBody()->write((string) json_encode([
                'error' => ['key' => 'blocked', 'message' => 'You are blocked from this board.'],
            ]));
            return $response->withStatus(403)->withHeader('Content-Type', 'application/json');
        }

        if ($this->editWindowExpired($idea)) {
            $response->getBody()->write((string) json_encode([
                'error' => [
                    'key'     => 'edit_window_expired',
                    'message' => 'This idea can no longer be edited.',
                ],
            ]));
            return $response->withStatus(422)->withHeader('Content-Type', 'application/json');
        }

        $parsed = $request->getParsedBody();
        $rawTitle    = is_array($parsed) ? trim((string) ($parsed['title'] ?? '')) : '';
        $rawBody     = is_array($parsed) ? trim((string) ($parsed['body'] ?? '')) : '';
        $honeypot    = is_array($parsed) ? (string) ($parsed[self::HONEYPOT_FIELD] ?? '') : '';
        $timeTrapVal = is_array($parsed) ? (string) ($parsed[self::TIME_TRAP_FIELD] ?? '') : '';

        // Bot defense 1: honeypot field — filled in → silent rejection (422, no hint).
        if ($honeypot !== '') {
            $response->getBody()->write((string) json_encode([
                'error' => ['key' => 'rejected', 'message' => 'The request was rejected.'],
            ]));
            return $response->withStatus(422)->withHeader('Content-Type', 'application/json');
        }

        // Bot defense 2: time trap — too fast → silent rejection (422, no hint).
        if (!$this->timeTrap->verify($timeTrapVal)) {
            $response->getBody()->write((string) json_encode([
                'error' => ['key' => 'rejected', 'message' => 'The request was rejected.'],
            ]));
            return $response->withStatus(422)->withHeader('Content-Type', 'application/json');
        }

        // Validation via Symfony Validator.
        $validator   = Validation::createValidator();
        $titleErrors = $validator->validate($rawTitle, [
            new Assert\NotBlank(message: 'The title must not be empty.'),
            new Assert\Length(
                min: 3,
                max: 200,
                minMessage: 'The title must be at least {{ limit }} characters long.',
                maxMessage: 'The title must be at most {{ limit }} characters long.',
            ),
        ]);
        $bodyErrors  = $validator->validate($rawBody, [
            new Assert\NotBlank(message: 'The description must not be empty.'),
            new Assert\Length(
                min: 10,
                minMessage: 'The description must be at least {{ limit }} characters long.',
            ),
        ]);

        /** @var array<string, string> $fields */
        $fields = [];
        foreach ($titleErrors as $e) {
            $fields['title'] = (string) $e->getMessage();
            break;
        }
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
                    'values'  => ['title' => $rawTitle, 'body' => $rawBody],
                ],
            ]));
            return $response->withStatus(422)->withHeader('Content-Type', 'application/json');
        }

        // Moderation hard block: after structural validation, before the DB update.
        $boardId = (int) $board['id'];
        $moderationEnabled = !$this->moderationConfigRepo instanceof ModerationConfigRepository
            || $this->moderationConfigRepo->isModerationEnabled($boardId);

        $effectiveModeration = $this->moderation;
        if ($moderationEnabled && $this->moderationConfigRepo instanceof ModerationConfigRepository) {
            $customWords = $this->moderationConfigRepo->wordList($boardId);
            if ($customWords !== []) {
                $effectiveModeration = $this->moderation->withAdditionalWords($customWords);
            }
        }

        $modResult = $moderationEnabled ? $effectiveModeration->check($rawTitle, $rawBody) : ['clean' => true, 'hits' => []];
        if (!$modResult['clean']) {
            $this->audit->log('idea.moderation_blocked', [
                'board_id'  => $boardId,
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

        // Normalization + update (board-scoped, author-scoped, prepared statement).
        $titleNormalized = $this->normalizer->normalize($rawTitle);
        $authorId        = (int) ($user['id'] ?? 0);

        $this->ideaRepo->updateOwn($ideaId, $authorId, $boardId, $rawTitle, $titleNormalized, $rawBody);

        $this->audit->log('idea.updated', ['board_id' => $boardId, 'idea_id' => $ideaId]);

        $response->getBody()->write((string) json_encode(['ok' => true]));
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }
}
