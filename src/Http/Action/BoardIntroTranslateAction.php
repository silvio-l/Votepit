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
use Votepit\Persistence\TranslationRepository;
use Votepit\Security\RateLimiter;
use Votepit\Translation\TranslationProvider;
use Votepit\Translation\TranslationUnavailableException;

/**
 * POST /{board}/translate-intro — body `{"target_locale": "en"}` →
 * translates a board's tenant-authored intro text into the requested
 * locale, caching the result (TranslationRepository, entity_type
 * 'board_intro') exactly like IdeaTranslateAction caches an idea's
 * title+body — see that class's doc for the full cache/rate-limit
 * rationale, mirrored here 1:1.
 *
 * AuthZ: user (via AuthZMiddleware::user() in AppFactory) — same
 * "authenticated voters only" boundary as idea/comment translation
 * (BoardPage's own doc: "anonymous voters never see translated content").
 * The intro itself stays fully visible to anonymous visitors via the
 * normal board page; only the machine-translated variant requires login.
 *
 * Reuses `boards.translation_enabled` (the same per-board opt-in as
 * idea/comment translation) — fail-secure, an unset/unknown flag is
 * "not opted in", never "translate anyway".
 *
 * A board with no intro (empty/NULL) has nothing to translate → 404,
 * same shape as an unknown board/idea.
 */
final readonly class BoardIntroTranslateAction
{
    // Matches BrandingValidator::MAX_INTRO_LENGTH — the actual validated
    // maximum an intro can ever be, not an arbitrary truncation point.
    private const MAX_CHARS = 1000;

    public function __construct(
        private BoardRepository $boardRepo,
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

        $intro = is_string($board['intro'] ?? null) ? trim($board['intro']) : '';
        if ($intro === '') {
            return $this->notFound($response);
        }

        $targetLocale = $this->parseTargetLocale($request);
        if ($targetLocale === null) {
            return $this->validationError($response);
        }

        $boardId    = (int) $board['id'];
        $sourceHash = hash('sha256', $intro);

        $cached = $this->translationRepo->findCached('board_intro', $boardId, $targetLocale, $sourceHash);
        if ($cached !== null) {
            return $this->success($response, $cached['translated_body'], $cached['detected_source_locale'], true);
        }

        if (!$this->checkRateLimit($request)) {
            return $this->rateLimited($response);
        }

        try {
            $result = $this->translationProvider->translate(mb_substr($intro, 0, self::MAX_CHARS), $targetLocale);
        } catch (TranslationUnavailableException) {
            return $this->unavailable($response);
        }

        $this->translationRepo->upsert(
            'board_intro',
            $boardId,
            $targetLocale,
            $sourceHash,
            $result['detected_source_locale'],
            null,
            $result['text'],
            $this->providerName,
        );

        return $this->success($response, $result['text'], $result['detected_source_locale'], false);
    }

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
            return $this->rateLimiter->hit('translation:board_intro:' . $userId, $this->rateLimit, $this->rateLimitWindow);
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
            'error' => ['key' => 'not_found', 'message' => 'Board has no intro to translate.'],
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
        string $translatedBody,
        string $detectedSourceLocale,
        bool $cached,
    ): ResponseInterface {
        $response->getBody()->write((string) json_encode([
            'translated_body'         => $translatedBody,
            'detected_source_locale'  => $detectedSourceLocale,
            'cached'                  => $cached,
        ]));
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }
}
