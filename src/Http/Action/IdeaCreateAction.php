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
 * POST /{board}/ideas — create an idea.
 *
 * AuthZ: user (via AuthZMiddleware::user() in AppFactory).
 * CSRF: globally enforced (CsrfMiddleware in the POST path).
 * RateLimit `idea:submit`: per-action rate limit (attached in AppFactory).
 *
 * Validation: title 3..200 characters, body min. 10 characters — Symfony
 * Validator. On error → 422 + the unified JSON error contract.
 * Success → 201 JSON `{"ok": true, "id": N}`.
 *
 * Moderation hard block, honeypot, time trap.
 *
 * Board-scoped user block — a thin inline guard directly here (no central
 * middleware), because the board is already loaded at this point. The
 * accountwide check additionally and unchangedly runs via
 * BlockCheckMiddleware before this action is even reached —
 * BlockRepository::isBlocked() with board_id set covers both levels
 * (account- and board-scoped) here in one query.
 */
final readonly class IdeaCreateAction
{
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

        // Logged-in user — AuthZMiddleware::user() ensures they're present.
        /** @var array<string, mixed> $user */
        $user   = $request->getAttribute(AuthNMiddleware::ATTR_USER);
        $userId = (int) ($user['id'] ?? 0);

        if ($this->blockRepo->isBlocked($accountId, $userId, (int) $board['id'])) {
            $response->getBody()->write((string) json_encode([
                'error' => ['key' => 'blocked', 'message' => 'You are blocked from this board.'],
            ]));
            return $response->withStatus(403)->withHeader('Content-Type', 'application/json');
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

        // Validation via Symfony Validator (already a dependency).
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

        // Moderation hard block: after structural validation, before the DB entry.
        // Per-board toggle: when "off", only the word filter is skipped —
        // honeypot, time trap, CSRF and rate limit always run (already executed above).
        $boardId = (int) $board['id'];
        $moderationEnabled = !$this->moderationConfigRepo instanceof \Votepit\Persistence\ModerationConfigRepository
            || $this->moderationConfigRepo->isModerationEnabled($boardId);

        $effectiveModeration = $this->moderation;
        if ($moderationEnabled && $this->moderationConfigRepo instanceof \Votepit\Persistence\ModerationConfigRepository) {
            $customWords = $this->moderationConfigRepo->wordList($boardId);
            if ($customWords !== []) {
                $effectiveModeration = $this->moderation->withAdditionalWords($customWords);
            }
        }

        $modResult = $moderationEnabled ? $effectiveModeration->check($rawTitle, $rawBody) : ['clean' => true, 'hits' => []];
        if (!$modResult['clean']) {
            // Log masked — raw hits must never land in the log.
            $this->audit->log('idea.moderation_blocked', [
                'board_id'  => (int) $board['id'],
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

        // Normalization (TitleNormalizer, compatible key format).
        $titleNormalized = $this->normalizer->normalize($rawTitle);

        // Create board-scoped (prepared statement via IdeaRepository).
        $authorId = (int) ($user['id'] ?? 0);

        $ideaId = $this->ideaRepo->create($boardId, $authorId, $rawTitle, $titleNormalized, $rawBody);

        $this->audit->log('idea.created', ['board_id' => $boardId, 'idea_id' => $ideaId]);

        $response->getBody()->write((string) json_encode(['ok' => true, 'id' => $ideaId]));
        return $response->withStatus(201)->withHeader('Content-Type', 'application/json');
    }
}
