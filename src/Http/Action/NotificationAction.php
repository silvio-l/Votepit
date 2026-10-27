<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Doctrine\DBAL\Exception as DbalException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Http\Middleware\AuthNMiddleware;
use Votepit\Persistence\NotificationRepository;
use Votepit\Persistence\TranslationRepository;
use Votepit\Security\RateLimiter;
use Votepit\Translation\TranslationProvider;
use Votepit\Translation\TranslationUnavailableException;

/**
 * GET    /notifications           — the caller's inbox (own account-scoped
 *                                    notifications + every broadcast).
 * POST   /notifications/{id}/read — mark one notification read.
 * DELETE /notifications/{id}      — dismiss (remove) one notification from
 *                                    the caller's own inbox, permanently.
 * POST   /notifications/translate — bulk-translate a set of the caller's
 *                                    own visible notifications (see
 *                                    translate() below).
 *
 * AuthZ: user() — any logged-in user, since listForUser() itself already
 * scopes to the caller's own account memberships (NotificationRepository).
 */
final readonly class NotificationAction
{
    // Notification titles are VARCHAR(200); bodies are pre-built sentences
    // (e.g. "There's a new comment on \"{$ideaTitle}\".") that in practice
    // never approach this — same truncation point as idea/comment
    // translation, kept for defense in depth rather than a real limit.
    private const MAX_CHARS = 500;

    public function __construct(
        private NotificationRepository $notifications,
        private TranslationRepository $translationRepo,
        private TranslationProvider $translationProvider,
        private RateLimiter $rateLimiter,
        private int $rateLimit,
        private int $rateLimitWindow,
        private bool $translationAvailable = true,
        private string $providerName = 'google',
    ) {}

    public function list(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $userId = $this->userId($request);

        $notifications = array_map(
            $this->present(...),
            $this->notifications->listForUser($userId),
        );

        return $this->json($response, 200, ['notifications' => $notifications]);
    }

    /** @param array<string, mixed> $args */
    public function markRead(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $userId = $this->userId($request);
        $id     = is_numeric($args['id'] ?? null) ? (int) $args['id'] : 0;

        if ($id <= 0 || !$this->notifications->isVisibleToUser($id, $userId)) {
            return $this->json($response, 404, ['error' => ['key' => 'not_found', 'message' => 'Notification not found.']]);
        }

        $this->notifications->markRead($id, $userId);

        return $this->json($response, 200, ['ok' => true]);
    }

    /** @param array<string, mixed> $args */
    public function dismiss(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $userId = $this->userId($request);
        $id     = is_numeric($args['id'] ?? null) ? (int) $args['id'] : 0;

        if ($id <= 0 || !$this->notifications->isVisibleToUser($id, $userId)) {
            return $this->json($response, 404, ['error' => ['key' => 'not_found', 'message' => 'Notification not found.']]);
        }

        $this->notifications->dismiss($id, $userId);

        return $this->json($response, 200, ['ok' => true]);
    }

    /**
     * POST /notifications/translate — body
     * `{"notification_ids": [1,2,3], "target_locale": "en"}` → bulk-
     * translates title+body of every listed notification into the
     * requested locale in one request (mirrors CommentTranslateAction's
     * whole-thread-in-one-call shape: one rate-limit hit per request, not
     * per notification). Covers operator broadcast announcements too —
     * they are physically 'notifications' rows with scope='broadcast'
     * (NotificationRepository::createBroadcast()), so no separate action
     * is needed for them.
     *
     * Every id is re-checked via isVisibleToUser() before anything else —
     * an id the caller cannot see (someone else's account-scoped
     * notification, or a stale/dismissed id) is never translated, never
     * counted toward the batch's cache/provider decision, and comes back
     * as `{"id": ..., "ok": false}` in the response rather than failing
     * the whole request.
     *
     * Not board-bound (unlike idea/comment translation) — there is no
     * per-board opt-in to check here; availability is purely
     * Config::translationConfigured() (passed in as $translationAvailable
     * at construction), same as the "not configured at all" branch of
     * CommentTranslateAction.
     */
    public function translate(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $userId = $this->userId($request);

        $targetLocale = $this->parseTargetLocale($request);
        if ($targetLocale === null) {
            return $this->json($response, 422, [
                'error' => ['key' => 'validation_error', 'message' => 'A valid target_locale is required.'],
            ]);
        }

        $parsed = $request->getParsedBody();
        $rawIds = is_array($parsed) && is_array($parsed['notification_ids'] ?? null)
            ? $parsed['notification_ids']
            : [];

        /** @var list<int> $ids */
        $ids = array_values(array_unique(array_map(
            static fn (mixed $v): int => (int) $v,
            array_filter($rawIds, is_numeric(...)),
        )));

        /** @var list<array{id: int, title: string, body: string, hash: string, cached: array{translated_title: string|null, translated_body: string, detected_source_locale: string}|null}> $prepared */
        $prepared = [];
        $invisible = [];
        $needsProvider = false;

        foreach ($ids as $id) {
            if ($id <= 0 || !$this->notifications->isVisibleToUser($id, $userId)) {
                $invisible[] = $id;
                continue;
            }

            $row = $this->notifications->findById($id);
            if ($row === null) {
                $invisible[] = $id;
                continue;
            }

            $title = (string) $row['title'];
            $body  = (string) $row['body'];
            $hash  = hash('sha256', $title . "\x1e" . $body);
            $cached = $this->translationRepo->findCached('notification', $id, $targetLocale, $hash);

            if ($cached === null) {
                $needsProvider = true;
            }

            $prepared[] = ['id' => $id, 'title' => $title, 'body' => $body, 'hash' => $hash, 'cached' => $cached];
        }

        if ($needsProvider && !$this->translationAvailable) {
            return $this->json($response, 503, [
                'error' => ['key' => 'translation_unavailable', 'message' => 'Translation is currently unavailable.'],
            ]);
        }

        if ($needsProvider && !$this->checkRateLimit($request, $userId)) {
            return $this->json($response, 429, [
                'error' => ['key' => 'rate_limited', 'message' => 'Too many requests. Please try again shortly.'],
            ])->withHeader('Retry-After', (string) $this->rateLimitWindow);
        }

        $results = [];
        foreach ($invisible as $id) {
            $results[] = ['id' => $id, 'ok' => false];
        }

        foreach ($prepared as $entry) {
            if ($entry['cached'] !== null) {
                $results[] = [
                    'id'                      => $entry['id'],
                    'ok'                      => true,
                    'translated_title'        => $entry['cached']['translated_title'],
                    'translated_body'         => $entry['cached']['translated_body'],
                    'detected_source_locale'  => $entry['cached']['detected_source_locale'],
                    'cached'                  => true,
                ];
                continue;
            }

            try {
                // Body first, then force the title to the same detected
                // locale — see IdeaTranslateAction for why (a short title
                // auto-detects unreliably on its own).
                $bodyResult  = $this->translationProvider->translate(mb_substr($entry['body'], 0, self::MAX_CHARS), $targetLocale);
                $titleResult = $this->translationProvider->translate(
                    mb_substr($entry['title'], 0, self::MAX_CHARS),
                    $targetLocale,
                    $bodyResult['detected_source_locale'],
                );
            } catch (TranslationUnavailableException) {
                $results[] = ['id' => $entry['id'], 'ok' => false];
                continue;
            }

            $this->translationRepo->upsert(
                'notification',
                $entry['id'],
                $targetLocale,
                $entry['hash'],
                $bodyResult['detected_source_locale'],
                $titleResult['text'],
                $bodyResult['text'],
                $this->providerName,
            );

            $results[] = [
                'id'                      => $entry['id'],
                'ok'                      => true,
                'translated_title'        => $titleResult['text'],
                'translated_body'         => $bodyResult['text'],
                'detected_source_locale'  => $bodyResult['detected_source_locale'],
                'cached'                  => false,
            ];
        }

        return $this->json($response, 200, ['notifications' => $results]);
    }

    /** True = allowed to proceed. Only called when the batch has at least one cache MISS. */
    private function checkRateLimit(ServerRequestInterface $request, int $userId): bool
    {
        $user = $request->getAttribute(AuthNMiddleware::ATTR_USER);
        if (is_array($user) && (bool) ($user['is_test_account'] ?? false)) {
            return true;
        }

        if ($userId <= 0) {
            return true;
        }

        try {
            return $this->rateLimiter->hit('translation:notification:' . $userId, $this->rateLimit, $this->rateLimitWindow);
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

    /**
     * @param  array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function present(array $row): array
    {
        return [
            'id'         => (int) $row['id'],
            'scope'      => (string) $row['scope'],
            'type'       => (string) $row['type'],
            'title'      => (string) $row['title'],
            'body'       => (string) $row['body'],
            'link_path'  => $row['link_path'],
            'created_at' => $row['created_at'],
            'is_read'    => (bool) $row['is_read'],
        ];
    }

    private function userId(ServerRequestInterface $request): int
    {
        /** @var array<string, mixed>|null $user */
        $user = $request->getAttribute(AuthNMiddleware::ATTR_USER);
        return is_array($user) ? (int) ($user['id'] ?? 0) : 0;
    }

    /** @param array<string, mixed> $payload */
    private function json(ResponseInterface $response, int $status, array $payload): ResponseInterface
    {
        $response->getBody()->write((string) json_encode($payload));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
