<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Doctrine\DBAL\Exception as DbalException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Domain\TranslationGate;
use Votepit\Http\Middleware\AccountContextMiddleware;
use Votepit\Http\Middleware\AuthNMiddleware;
use Votepit\Persistence\BoardRepository;
use Votepit\Persistence\IdeaRepository;
use Votepit\Persistence\TranslationRepository;
use Votepit\Security\RateLimiter;
use Votepit\Translation\TranslationProvider;
use Votepit\Translation\TranslationUnavailableException;

/**
 * POST /{board}/ideas/{id}/translate — body `{"target_locale": "en"}` →
 * translates an idea's title+body into the requested locale, caching the
 * result (TranslationRepository) so the same content is never translated
 * twice for the same target locale.
 *
 * AuthZ: user (via AuthZMiddleware::user() in AppFactory).
 * CSRF: globally enforced.
 * RateLimit `translation:idea`: checked HERE, not as route middleware —
 * deliberately AFTER the cache lookup below, so a cache HIT never counts
 * against it. Rationale (idea-comment-translation follow-up, found via a
 * real staging session): once an idea's translation is cached, every
 * subsequent viewer/reload/language-toggle should keep working forever
 * regardless of how many *other* ideas were translated recently — a
 * middleware-level check (the previous design) blocked even already-
 * cached content the moment the bucket filled up, which is both wasteful
 * (the whole point of caching is to make repeat views free) and
 * confusing (a perfectly translatable, previously-seen idea started
 * reporting itself as "currently unavailable").
 *
 * Structurally board-scoped: the idea is loaded board-scoped via
 * IdeaRepository::findInBoard() BEFORE any translation logic runs —
 * unknown slug or idea outside the board → 404, exactly as every other
 * idea-scoped action.
 *
 * Cache-hit (source_hash still matches the idea's current title+body) →
 * the provider is never called again. Cache-miss (no row, or the idea was
 * edited since the last translation) → the rate limit is checked, then
 * the provider is called (truncated to MAX_CHARS regardless of what's
 * actually stored — defense in depth for rows written before the
 * 500-character content limit existed), and the result is persisted.
 *
 * Not configured (NullTranslationProvider), the board hasn't opted in
 * (`boards.translation_enabled`, default off — idea-comment-translation's
 * per-board follow-up), or a real provider failure → 503
 * `{"error":{"key":"translation_unavailable"}}`, never a 500 — the idea's
 * own visibility through GET /{board}/ideas/{id} is entirely unaffected
 * either way. Fail-secure: an unrecognized/missing flag is treated as "not
 * opted in", never as "translate anyway".
 */
final readonly class IdeaTranslateAction
{
    private const MAX_CHARS = 500;

    public function __construct(
        private BoardRepository $boardRepo,
        private IdeaRepository $ideaRepo,
        private TranslationRepository $translationRepo,
        private TranslationProvider $translationProvider,
        private TranslationGate $translationGate,
        private RateLimiter $rateLimiter,
        private int $rateLimit,
        private int $rateLimitWindow,
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

        if (!$this->translationGate->boardEnabled($board, $accountId, $request->getAttribute(AuthNMiddleware::ATTR_USER))) {
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

        $title = (string) $idea['title'];
        $body  = (string) $idea['body'];

        // Hashed over the FULL (untruncated) current title+body — an edit
        // anywhere in either field invalidates the cache, independent of
        // the MAX_CHARS truncation applied only to the provider call below.
        $sourceHash = hash('sha256', $title . "\x1e" . $body);

        $cached = $this->translationRepo->findCached('idea', $ideaId, $targetLocale, $sourceHash);
        if ($cached !== null) {
            return $this->success($response, $cached['translated_title'], $cached['translated_body'], $cached['detected_source_locale'], true);
        }

        if (!$this->checkRateLimit($request)) {
            return $this->rateLimited($response);
        }

        try {
            // Body first: it's the more reliable signal for detecting the
            // idea's actual language (more text than the title). The title
            // is then translated FORCING that same source locale — a short,
            // English-loanword-heavy title (e.g. "Votepit als native Mobile
            // App (iOS & Android)") auto-detects as English on its own,
            // silently skipping translation while the body (correctly
            // detected as German) gets translated — same idea, inconsistent
            // result. See GoogleTranslateProviderTest for the reproduction.
            $bodyResult  = $this->translationProvider->translate(mb_substr($body, 0, self::MAX_CHARS), $targetLocale);
            $titleResult = $this->translationProvider->translate(
                mb_substr($title, 0, self::MAX_CHARS),
                $targetLocale,
                $bodyResult['detected_source_locale'],
            );
        } catch (TranslationUnavailableException) {
            return $this->unavailable($response);
        }

        $this->translationRepo->upsert(
            'idea',
            $ideaId,
            $targetLocale,
            $sourceHash,
            $bodyResult['detected_source_locale'],
            $titleResult['text'],
            $bodyResult['text'],
            $this->providerName,
        );

        return $this->success($response, $titleResult['text'], $bodyResult['text'], $bodyResult['detected_source_locale'], false);
    }

    /**
     * True = allowed to proceed (limit not hit, no limit configured, DB
     * fail-open, or a test account). Only ever called on a cache MISS —
     * see the class doc for why a cache hit must never reach here.
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
            return $this->rateLimiter->hit('translation:idea:' . $userId, $this->rateLimit, $this->rateLimitWindow);
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

    private function success(
        ResponseInterface $response,
        ?string $translatedTitle,
        string $translatedBody,
        string $detectedSourceLocale,
        bool $cached,
    ): ResponseInterface {
        $response->getBody()->write((string) json_encode([
            'translated_title'        => $translatedTitle,
            'translated_body'         => $translatedBody,
            'detected_source_locale'  => $detectedSourceLocale,
            'cached'                  => $cached,
        ]));
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }
}
