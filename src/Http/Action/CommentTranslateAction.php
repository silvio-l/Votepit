<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Doctrine\DBAL\Exception as DbalException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Http\Middleware\AccountContextMiddleware;
use Votepit\Http\Middleware\AuthNMiddleware;
use Votepit\Persistence\BoardRepository;
use Votepit\Persistence\CommentRepository;
use Votepit\Persistence\IdeaRepository;
use Votepit\Persistence\TranslationRepository;
use Votepit\Security\RateLimiter;
use Votepit\Translation\TranslationProvider;
use Votepit\Translation\TranslationUnavailableException;

/**
 * POST /{board}/ideas/{id}/comments/translate — body
 * `{"target_locale": "en"}` → translates EVERY comment currently on the
 * idea into the requested locale in one request (bulk, not one call per
 * comment). Deliberately one endpoint call for the whole thread — the
 * per-action rate limit `translation:comment` therefore counts one hit
 * per request regardless of comment count, so a long thread can't be used
 * to bypass rate limiting via comment count.
 *
 * AuthZ: user; CSRF globally enforced (both attached in AppFactory, same
 * as IdeaTranslateAction).
 *
 * Board-/idea-scoping: the idea is loaded board-scoped via
 * IdeaRepository::findInBoard() BEFORE any comment is touched — unknown
 * slug or idea outside the board → 404, exactly like every other
 * idea-scoped action.
 *
 * Per comment, cache-hit/cache-miss mirrors IdeaTranslateAction: a comment
 * whose translation is already cached (source_hash still matches its
 * current body) is served from cache without a provider call; a comment
 * edited since its last translation is a cache miss and is re-translated.
 * A single comment's provider failure does NOT fail the whole batch — the
 * response distinguishes per-comment success/failure via the `ok` flag.
 *
 * Translation not configured at all is handled up front (before touching
 * any comment): the whole request responds 503, the same shape as
 * IdeaTranslateAction's "not configured" response — a mid-batch REAL
 * provider failure (config IS valid, one call transiently fails) is what
 * produces the per-comment partial-success shape instead.
 *
 * Board opt-in (`boards.translation_enabled`, default off) is checked
 * BEFORE that, unconditionally (even for an all-cached batch) — same
 * fail-secure reasoning as IdeaTranslateAction: a board that has not (or
 * no longer) opted in never gets translated content, cached or not.
 *
 * RateLimit `translation:comment`: checked HERE, not as route middleware,
 * and only when at least one comment in the batch is an actual cache MISS
 * (`$needsProvider` below) — same rationale as IdeaTranslateAction: a
 * thread that is already fully cached must keep rendering translated for
 * every viewer/reload forever, never blocked by a bucket that filled up
 * translating OTHER ideas/threads.
 */
final readonly class CommentTranslateAction
{
    private const MAX_CHARS = 500;

    public function __construct(
        private BoardRepository $boardRepo,
        private IdeaRepository $ideaRepo,
        private CommentRepository $commentRepo,
        private TranslationRepository $translationRepo,
        private TranslationProvider $translationProvider,
        private RateLimiter $rateLimiter,
        private int $rateLimit,
        private int $rateLimitWindow,
        private bool $translationAvailable = true,
        private string $providerName = 'google',
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
            return $this->notFound($response);
        }

        if (!(bool) ($board['translation_enabled'] ?? false)) {
            return $this->unavailable($response);
        }

        $boardId = (int) $board['id'];
        $ideaId  = (int) ($args['id'] ?? 0);

        $idea = $this->ideaRepo->findInBoard($boardId, $ideaId);
        if (!is_array($idea)) {
            return $this->notFound($response);
        }

        $targetLocale = $this->parseTargetLocale($request);
        if ($targetLocale === null) {
            return $this->validationError($response);
        }

        $comments = $this->commentRepo->listByIdea($ideaId);

        // Pre-compute cache lookups so we know up front whether this
        // request needs the provider at all — if translation is entirely
        // unconfigured AND at least one comment would need a fresh
        // provider call, respond 503 for the whole batch instead of a
        // stream of individually-failed comments.
        /** @var list<array{id: int, body: string, hash: string, cached: array{translated_body: string, detected_source_locale: string}|null}> $prepared */
        $prepared = [];
        $needsProvider = false;
        foreach ($comments as $comment) {
            $commentId = (int) $comment['id'];
            $body      = (string) $comment['body'];
            $hash      = hash('sha256', $body);
            $cached    = $this->translationRepo->findCached('comment', $commentId, $targetLocale, $hash);

            if ($cached === null) {
                $needsProvider = true;
            }

            $prepared[] = ['id' => $commentId, 'body' => $body, 'hash' => $hash, 'cached' => $cached];
        }

        if ($needsProvider && !$this->translationAvailable) {
            return $this->unavailable($response);
        }

        if ($needsProvider && !$this->checkRateLimit($request)) {
            return $this->rateLimited($response);
        }

        $results = [];
        foreach ($prepared as $entry) {
            if ($entry['cached'] !== null) {
                $results[] = [
                    'id'                      => $entry['id'],
                    'ok'                      => true,
                    'translated_body'         => $entry['cached']['translated_body'],
                    'detected_source_locale'  => $entry['cached']['detected_source_locale'],
                    'cached'                  => true,
                ];
                continue;
            }

            try {
                $result = $this->translationProvider->translate(mb_substr($entry['body'], 0, self::MAX_CHARS), $targetLocale);
            } catch (TranslationUnavailableException) {
                $results[] = ['id' => $entry['id'], 'ok' => false];
                continue;
            }

            $this->translationRepo->upsert(
                'comment',
                $entry['id'],
                $targetLocale,
                $entry['hash'],
                $result['detected_source_locale'],
                null,
                $result['text'],
                $this->providerName,
            );

            $results[] = [
                'id'                      => $entry['id'],
                'ok'                      => true,
                'translated_body'         => $result['text'],
                'detected_source_locale'  => $result['detected_source_locale'],
                'cached'                  => false,
            ];
        }

        $response->getBody()->write((string) json_encode(['comments' => $results]));
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }

    /**
     * True = allowed to proceed. Only ever called when the batch has at
     * least one cache MISS — see the class doc for why an all-cached batch
     * must never reach here.
     */
    private function checkRateLimit(ServerRequestInterface $request): bool
    {
        $user = $request->getAttribute(AuthNMiddleware::ATTR_USER);
        if (is_array($user) && (bool) ($user['is_test_account'] ?? false)) {
            return true;
        }

        $userId = is_array($user) ? (string) ($user['id'] ?? '') : '';
        if ($userId === '') {
            return true;
        }

        try {
            return $this->rateLimiter->hit('translation:comment:' . $userId, $this->rateLimit, $this->rateLimitWindow);
        } catch (DbalException) {
            return true; // fail-open, same rationale as RateLimitMiddleware
        }
    }

    private function parseTargetLocale(ServerRequestInterface $request): ?string
    {
        $parsed = $request->getParsedBody();
        $locale = is_array($parsed) ? trim((string) ($parsed['target_locale'] ?? '')) : '';

        if ($locale === '' || preg_match('/^[a-zA-Z]{2,3}(-[a-zA-Z]{2,4})?$/', $locale) !== 1) {
            return null;
        }

        return $locale;
    }

    private function notFound(ResponseInterface $response): ResponseInterface
    {
        $response->getBody()->write((string) json_encode([
            'error' => ['key' => 'not_found', 'message' => 'Idea not found.'],
        ]));
        return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
    }

    private function validationError(ResponseInterface $response): ResponseInterface
    {
        $response->getBody()->write((string) json_encode([
            'error' => ['key' => 'validation_error', 'message' => 'A valid target_locale is required.'],
        ]));
        return $response->withStatus(422)->withHeader('Content-Type', 'application/json');
    }

    private function unavailable(ResponseInterface $response): ResponseInterface
    {
        $response->getBody()->write((string) json_encode([
            'error' => ['key' => 'translation_unavailable', 'message' => 'Translation is currently unavailable.'],
        ]));
        return $response->withStatus(503)->withHeader('Content-Type', 'application/json');
    }

    private function rateLimited(ResponseInterface $response): ResponseInterface
    {
        $response->getBody()->write((string) json_encode([
            'error' => ['key' => 'rate_limited', 'message' => 'Too many requests. Please try again shortly.'],
        ]));
        return $response->withStatus(429)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Retry-After', (string) $this->rateLimitWindow);
    }
}
