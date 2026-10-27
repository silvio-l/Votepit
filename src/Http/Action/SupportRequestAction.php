<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Doctrine\DBAL\Exception as DbalException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validation;
use Votepit\Http\Middleware\AccountContextMiddleware;
use Votepit\Http\Middleware\AuthNMiddleware;
use Votepit\Logging\AuditLogger;
use Votepit\Mail\CommentNotificationMailer;
use Votepit\Persistence\AccountRepository;
use Votepit\Persistence\NotificationRepository;
use Votepit\Persistence\SupportRequestRepository;
use Votepit\Persistence\TranslationRepository;
use Votepit\Persistence\UserRepository;
use Votepit\Security\RateLimiter;
use Votepit\Support\SupportCategory;
use Votepit\Translation\TranslationProvider;
use Votepit\Translation\TranslationUnavailableException;

/**
 * POST /{account}/admin/support               — open a new ticket from the dashboard.
 * GET  /{account}/admin/support                — the caller's own account's tickets (summaries only).
 * GET  /{account}/admin/support/{id}           — one ticket's full thread, scoped to the caller's account.
 * POST /{account}/admin/support/{id}/reply     — post a message to one of the account's own tickets.
 * GET  /operator/support                      — operator inbox, every account (summaries only,
 *                                                 each carrying account_slug so a queue spanning
 *                                                 accounts stays scannable without opening every
 *                                                 ticket). Query params: status, category, q
 *                                                 (searches subject + every message body), sort
 *                                                 (one of SupportRequestRepository::allowedSorts()).
 * GET  /operator/support/{id}                 — one ticket's full thread, unscoped.
 * POST /operator/support/{id}/reply           — post a message as the operator (sets status 'answered').
 * POST /operator/support/{id}/status          — set status directly (e.g. close without a final reply).
 * POST /{account}/admin/support/{id}/translate — bulk-translate every message
 *                                                 on one of the account's own tickets (translateMine()).
 * POST /operator/support/{id}/translate       — same, unscoped, for the operator side (operatorTranslate()).
 *
 * A lightweight ticket system: support_requests is the ticket header,
 * support_messages (migrations/0026_add_support_messages.sql) the ordered
 * thread — either side can post to a ticket over time, and a customer
 * message reopens it (SupportRequestRepository::addMessage), the same way
 * common ticket systems treat "customer replied" as "needs attention
 * again" rather than requiring an explicit reopen action.
 *
 * submit()/listMine()/getThreadMine()/replyMine() are account-scoped (AuthZ:
 * accountAdmin — owner|admin; handling support requests is part of the
 * 'admin' role's scope, moderator is restricted to comment/idea moderation
 * only and does not pass this). A
 * member only ever sees/replies to their OWN account's tickets
 * (findByIdForAccount), never another tenant's — the operator endpoints are
 * unscoped (findByIdForOperator), mirroring AbuseReportAction's operator
 * tier split.
 *
 * No email to the CUSTOMER anywhere (product decision, see
 * migrations/0024_add_notifications_remove_support_email.sql): a customer
 * never gives a contact email, and the return channel is an in-app
 * notification — every operator reply creates one pointing back at the
 * ticket (scope 'account'), and every new ticket / customer reply creates
 * one for every operator/support agent (scope 'operator', migrations/
 * 0041_add_operator_scoped_notifications.sql) — otherwise support activity
 * on the customer side was invisible until someone happened to revisit
 * GET /operator/support. The OPERATOR side is different: operators are
 * staff with their own accounts, so a new ticket / customer reply also
 * fans out an email to every operator/support agent who opted into
 * notify_support_ticket_email and has a confirmed notification_email
 * (migrations/0045_add_operator_notification_preferences.sql) — in-app
 * visibility is gated per-recipient at read time
 * (NotificationRepository::listForUser()), email is fanned out here.
 */
final readonly class SupportRequestAction
{
    private const ALLOWED_STATUSES = ['open', 'answered', 'closed'];

    // Message bodies are validated up to 8000 chars (validateBody()) —
    // this is a practical cap on what actually gets sent to the provider
    // per message, not the storage limit, since real support messages are
    // overwhelmingly much shorter than the validated maximum.
    private const MAX_CHARS = 2000;

    public function __construct(
        private SupportRequestRepository $requests,
        private NotificationRepository $notifications,
        private AuditLogger $audit,
        private AccountRepository $accounts,
        private UserRepository $users,
        private CommentNotificationMailer $notificationMailer,
        private TranslationRepository $translationRepo,
        private TranslationProvider $translationProvider,
        private RateLimiter $rateLimiter,
        private int $rateLimit,
        private int $rateLimitWindow,
        private bool $translationAvailable = true,
        private string $providerName = 'google',
    ) {}

    public function submit(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $accountId = (int) $request->getAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID);
        $userId    = $this->currentUserId($request);

        $parsed = $request->getParsedBody();
        $body   = is_array($parsed) ? $parsed : [];

        $category = trim((string) ($body['category'] ?? ''));
        $subject  = trim((string) ($body['subject'] ?? ''));
        $message  = trim((string) ($body['message'] ?? ''));

        /** @var array<string, string> $fields */
        $fields = [];

        if (!SupportCategory::isValid($category)) {
            $fields['category'] = 'Please choose a valid category.';
        }

        $validator = Validation::createValidator();

        foreach ($validator->validate($subject, [
            new Assert\NotBlank(message: 'Please enter a subject.'),
            new Assert\Length(max: 200, maxMessage: 'The subject must be at most {{ limit }} characters long.'),
        ]) as $e) {
            $fields['subject'] = (string) $e->getMessage();
            break;
        }

        foreach ($this->validateBody($message) as $e) {
            $fields['message'] = $e;
            break;
        }

        if ($fields !== []) {
            return $this->json($response, 422, [
                'error' => ['key' => 'validation_error', 'message' => 'Validation failed.', 'fields' => $fields],
            ]);
        }

        $requestId = $this->requests->create($accountId, $userId, $category, $subject, $message);

        $this->audit->log('support_request.submitted', [
            'request_id' => $requestId,
            'account_id' => $accountId,
            'user_id'    => $userId,
            'category'   => $category,
        ]);

        $notice = self::newRequestNotice($subject);
        $this->notifications->createForOperators(
            'support_reply',
            $notice['title'],
            $notice['body'],
            "/operator/support/{$requestId}",
        );
        $this->notifyOperatorsByEmail($notice['title'], $notice['body'], "/operator/support/{$requestId}");

        return $this->json($response, 201, ['ok' => true, 'id' => $requestId]);
    }

    public function listMine(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $accountId = (int) $request->getAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID);

        $requests = array_map(
            $this->presentRequest(...),
            $this->requests->listForAccount($accountId),
        );

        return $this->json($response, 200, ['requests' => $requests]);
    }

    /** @param array<string, mixed> $args */
    public function getThreadMine(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $accountId = (int) $request->getAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID);
        $requestId = is_numeric($args['id'] ?? null) ? (int) $args['id'] : 0;
        $ticket    = $requestId > 0 ? $this->requests->findByIdForAccount($requestId, $accountId) : null;

        if (!is_array($ticket)) {
            return $this->json($response, 404, ['error' => ['key' => 'not_found', 'message' => 'Ticket not found.']]);
        }

        return $this->json($response, 200, [
            'request'  => $this->presentRequest($ticket),
            'messages' => array_map($this->presentMessage(...), $this->requests->listMessages($requestId)),
        ]);
    }

    /** @param array<string, mixed> $args */
    public function replyMine(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $accountId = (int) $request->getAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID);
        $requestId = is_numeric($args['id'] ?? null) ? (int) $args['id'] : 0;
        $ticket    = $requestId > 0 ? $this->requests->findByIdForAccount($requestId, $accountId) : null;

        if (!is_array($ticket)) {
            return $this->json($response, 404, ['error' => ['key' => 'not_found', 'message' => 'Ticket not found.']]);
        }

        $parsed = $request->getParsedBody();
        $body   = trim((string) (is_array($parsed) ? ($parsed['message'] ?? '') : ''));

        foreach ($this->validateBody($body) as $e) {
            return $this->json($response, 422, ['error' => ['key' => 'validation_error', 'message' => $e]]);
        }

        $userId = $this->currentUserId($request);
        $this->requests->addMessage($requestId, 'customer', $userId, $body);

        $this->audit->log('support_request.replied', [
            'request_id' => $requestId,
            'account_id' => $accountId,
            'user_id'    => $userId,
        ]);

        $subject = (string) $ticket['subject'];
        $notice = self::newReplyNotice($subject);
        $this->notifications->createForOperators(
            'support_reply',
            $notice['title'],
            $notice['body'],
            "/operator/support/{$requestId}",
        );
        $this->notifyOperatorsByEmail($notice['title'], $notice['body'], "/operator/support/{$requestId}");

        return $this->json($response, 200, ['ok' => true, 'status' => 'open']);
    }

    /**
     * POST /{account}/admin/support/{id}/translate — bulk-translates every
     * message currently on one of the caller's own account's tickets
     * (mirrors CommentTranslateAction's whole-thread-in-one-call shape).
     * AuthZ: accountAdmin, scoped via findByIdForAccount — another
     * tenant's ticket id is a 404, exactly like getThreadMine().
     *
     * Not board-bound — availability is purely
     * Config::translationConfigured() ($translationAvailable), same as
     * NotificationAction::translate().
     *
     * @param array<string, mixed> $args
     */
    public function translateMine(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $accountId = (int) $request->getAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID);
        $requestId = is_numeric($args['id'] ?? null) ? (int) $args['id'] : 0;
        $ticket    = $requestId > 0 ? $this->requests->findByIdForAccount($requestId, $accountId) : null;

        if (!is_array($ticket)) {
            return $this->json($response, 404, ['error' => ['key' => 'not_found', 'message' => 'Ticket not found.']]);
        }

        return $this->translateThread($request, $response, $requestId, $this->currentUserId($request));
    }

    /**
     * POST /operator/support/{id}/translate — same as translateMine(), but
     * unscoped (findByIdForOperator), for the operator side of a ticket.
     *
     * @param array<string, mixed> $args
     */
    public function operatorTranslate(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $requestId = is_numeric($args['id'] ?? null) ? (int) $args['id'] : 0;
        $ticket    = $requestId > 0 ? $this->requests->findByIdForOperator($requestId) : null;

        if (!is_array($ticket)) {
            return $this->json($response, 404, ['error' => ['key' => 'not_found', 'message' => 'Ticket not found.']]);
        }

        return $this->translateThread($request, $response, $requestId, $this->actorId($request));
    }

    /**
     * Shared bulk-translate body for translateMine()/operatorTranslate() —
     * the ticket has already been resolved/scope-checked by the caller;
     * this only translates its messages. $rateLimitActorId is the customer
     * user id or the operator user id, whichever side called in — each
     * gets their own rate-limit bucket.
     */
    private function translateThread(
        ServerRequestInterface $request,
        ResponseInterface $response,
        int $requestId,
        int $rateLimitActorId,
    ): ResponseInterface {
        $parsed       = $request->getParsedBody();
        $targetLocale = is_array($parsed) ? trim((string) ($parsed['target_locale'] ?? '')) : '';
        if ($targetLocale === '' || preg_match('/^[a-zA-Z]{2,3}(-[a-zA-Z]{2,4})?$/', $targetLocale) !== 1) {
            return $this->json($response, 422, [
                'error' => ['key' => 'validation_error', 'message' => 'A valid target_locale is required.'],
            ]);
        }

        /** @var list<array{id: int, body: string, hash: string, cached: array{translated_body: string, detected_source_locale: string}|null}> $prepared */
        $prepared = [];
        $needsProvider = false;

        foreach ($this->requests->listMessages($requestId) as $message) {
            $messageId = (int) $message['id'];
            $body      = (string) $message['body'];
            $hash      = hash('sha256', $body);
            $cached    = $this->translationRepo->findCached('support_message', $messageId, $targetLocale, $hash);

            if ($cached === null) {
                $needsProvider = true;
            }

            $prepared[] = ['id' => $messageId, 'body' => $body, 'hash' => $hash, 'cached' => $cached];
        }

        if ($needsProvider && !$this->translationAvailable) {
            return $this->json($response, 503, [
                'error' => ['key' => 'translation_unavailable', 'message' => 'Translation is currently unavailable.'],
            ]);
        }

        if ($needsProvider && !$this->checkTranslateRateLimit($request, $rateLimitActorId)) {
            return $this->json($response, 429, [
                'error' => ['key' => 'rate_limited', 'message' => 'Too many requests. Please try again shortly.'],
            ])->withHeader('Retry-After', (string) $this->rateLimitWindow);
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
                'support_message',
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

        return $this->json($response, 200, ['messages' => $results]);
    }

    /** True = allowed to proceed. Only called when the batch has at least one cache MISS. */
    private function checkTranslateRateLimit(ServerRequestInterface $request, int $actorId): bool
    {
        $user = $request->getAttribute(AuthNMiddleware::ATTR_USER);
        if (is_array($user) && (bool) ($user['is_test_account'] ?? false)) {
            return true;
        }

        if ($actorId <= 0) {
            return true;
        }

        try {
            return $this->rateLimiter->hit('translation:support_message:' . $actorId, $this->rateLimit, $this->rateLimitWindow);
        } catch (DbalException) {
            return true; // fail-open, same rationale as RateLimitMiddleware
        }
    }

    public function operatorList(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $query = $request->getQueryParams();

        $status   = trim((string) ($query['status'] ?? ''));
        $category = trim((string) ($query['category'] ?? ''));
        $q        = trim((string) ($query['q'] ?? ''));
        $sort     = trim((string) ($query['sort'] ?? 'updated_at_desc'));

        if ($status !== '' && !in_array($status, self::ALLOWED_STATUSES, true)) {
            return $this->json($response, 422, [
                'error' => ['key' => 'invalid_input', 'message' => 'status must be "open", "answered" or "closed".'],
            ]);
        }

        if ($category !== '' && !SupportCategory::isValid($category)) {
            return $this->json($response, 422, [
                'error' => ['key' => 'invalid_input', 'message' => 'Please choose a valid category.'],
            ]);
        }

        if (!in_array($sort, SupportRequestRepository::allowedSorts(), true)) {
            return $this->json($response, 422, [
                'error' => ['key' => 'invalid_input', 'message' => 'Invalid sort value.'],
            ]);
        }

        $requests = array_map(
            $this->presentOperatorRequest(...),
            $this->requests->listAllForOperator(
                status: $status === '' ? null : $status,
                category: $category === '' ? null : $category,
                q: $q === '' ? null : $q,
                sort: $sort,
            ),
        );

        return $this->json($response, 200, ['requests' => $requests]);
    }

    /** @param array<string, mixed> $args */
    public function operatorGetThread(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $requestId = is_numeric($args['id'] ?? null) ? (int) $args['id'] : 0;
        $ticket    = $requestId > 0 ? $this->requests->findByIdForOperator($requestId) : null;

        if (!is_array($ticket)) {
            return $this->json($response, 404, ['error' => ['key' => 'not_found', 'message' => 'Ticket not found.']]);
        }

        $account = $this->accounts->findById((int) $ticket['account_id']);
        $user    = $this->users->findById((int) $ticket['user_id']);

        return $this->json($response, 200, [
            'request'   => $this->presentRequest($ticket),
            'messages'  => array_map($this->presentMessage(...), $this->requests->listMessages($requestId)),
            'account'   => $account === null ? null : $this->presentAccountContext($account),
            'requester' => $user === null ? null : $this->presentRequesterContext($user),
        ]);
    }

    /** @param array<string, mixed> $args */
    public function operatorReply(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $requestId = is_numeric($args['id'] ?? null) ? (int) $args['id'] : 0;
        $ticket    = $requestId > 0 ? $this->requests->findByIdForOperator($requestId) : null;
        if (!is_array($ticket)) {
            return $this->json($response, 404, ['error' => ['key' => 'not_found', 'message' => 'Ticket not found.']]);
        }

        $parsed = $request->getParsedBody();
        $reply  = trim((string) (is_array($parsed) ? ($parsed['reply'] ?? $parsed['message'] ?? '') : ''));

        if ($reply === '') {
            return $this->json($response, 422, [
                'error' => ['key' => 'validation_error', 'message' => 'Please enter a reply.'],
            ]);
        }

        $actorId = $this->actorId($request);
        $this->requests->addMessage($requestId, 'operator', $actorId, $reply);

        $this->audit->log('operator.support_request.replied', [
            'actor_tier' => 'operator',
            'actor_id'   => $actorId,
            'request_id' => $requestId,
        ]);

        $subject = (string) $ticket['subject'];
        $this->notifications->createForAccount(
            (int) $ticket['account_id'],
            'support_reply',
            'Reply to your support request',
            "Your request \"{$subject}\" has received a reply.",
            '/admin/support',
        );

        return $this->json($response, 200, ['ok' => true, 'status' => 'answered']);
    }

    /** @param array<string, mixed> $args */
    public function operatorSetStatus(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $requestId = is_numeric($args['id'] ?? null) ? (int) $args['id'] : 0;
        $ticket    = $requestId > 0 ? $this->requests->findByIdForOperator($requestId) : null;
        if (!is_array($ticket)) {
            return $this->json($response, 404, ['error' => ['key' => 'not_found', 'message' => 'Request not found.']]);
        }

        $parsed = $request->getParsedBody();
        $status = is_array($parsed) ? (string) ($parsed['status'] ?? '') : '';

        if (!in_array($status, self::ALLOWED_STATUSES, true)) {
            return $this->json($response, 422, [
                'error' => ['key' => 'invalid_input', 'message' => 'status must be "open", "answered" or "closed".'],
            ]);
        }

        $actorId = $this->actorId($request);
        $this->requests->setStatus($requestId, $status);

        $this->audit->log('operator.support_request.status_changed', [
            'actor_tier' => 'operator',
            'actor_id'   => $actorId,
            'request_id' => $requestId,
            'status'     => $status,
        ]);

        return $this->json($response, 200, ['ok' => true, 'status' => $status]);
    }

    /** @return list<string> at most one validation error message */
    private function validateBody(string $body): array
    {
        $validator = Validation::createValidator();
        foreach ($validator->validate($body, [
            new Assert\NotBlank(message: 'Please describe your request.'),
            new Assert\Length(min: 10, max: 8000, minMessage: 'Please describe it in a bit more detail (at least {{ limit }} characters).', maxMessage: 'The message must be at most {{ limit }} characters long.'),
        ]) as $e) {
            return [(string) $e->getMessage()];
        }

        return [];
    }

    /**
     * @param  array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function presentRequest(array $row): array
    {
        return [
            'id'         => (int) $row['id'],
            'account_id' => (int) $row['account_id'],
            'user_id'    => (int) $row['user_id'],
            'category'   => (string) $row['category'],
            'subject'    => (string) $row['subject'],
            'status'     => (string) $row['status'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }

    /**
     * Same shape as presentRequest(), plus the account's slug — the operator
     * inbox spans every account, so each row needs a visible account
     * identifier to triage at a glance without opening the ticket.
     *
     * @param  array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function presentOperatorRequest(array $row): array
    {
        return [...$this->presentRequest($row), 'account_slug' => (string) $row['account_slug']];
    }

    /**
     * @param  array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function presentMessage(array $row): array
    {
        return [
            'id'              => (int) $row['id'],
            'request_id'      => (int) $row['request_id'],
            'author_type'     => (string) $row['author_type'],
            'author_user_id'  => (int) $row['author_user_id'],
            'body'            => (string) $row['body'],
            'created_at'      => $row['created_at'],
        ];
    }

    /**
     * Just enough for an operator to identify the ticket's account when
     * following up in logs/other tooling — not a general account read
     * surface. No plaintext email is ever stored (only an HMAC), so this
     * deliberately stops at slug/name/plan.
     *
     * @param  array<string, mixed> $account
     * @return array<string, mixed>
     */
    private function presentAccountContext(array $account): array
    {
        return [
            'id'         => (int) $account['id'],
            'slug'       => $account['slug'],
            'name'       => $account['name'],
            'plan'       => $account['plan'],
            'created_at' => $account['created_at'],
        ];
    }

    /**
     * @param  array<string, mixed> $user
     * @return array<string, mixed>
     */
    private function presentRequesterContext(array $user): array
    {
        return [
            'id'         => (int) $user['id'],
            'public_id'  => $user['public_id'] ?? null,
            'username'   => $user['username'] ?? null,
            'created_at' => $user['created_at'],
        ];
    }

    /**
     * Title and body of the operator notification for a new ticket (shared
     * with bin/send-mail-previews.php).
     *
     * @return array{title: string, body: string}
     */
    public static function newRequestNotice(string $subject): array
    {
        return [
            'title' => 'New support request',
            'body'  => "A new request was submitted: \"{$subject}\".",
        ];
    }

    /**
     * Title and body of the operator notification for a customer reply
     * (shared with bin/send-mail-previews.php).
     *
     * @return array{title: string, body: string}
     */
    public static function newReplyNotice(string $subject): array
    {
        return [
            'title' => 'New reply on a support request',
            'body'  => "\"{$subject}\" has a new customer reply.",
        ];
    }

    /**
     * Emails every operator/support agent who opted into
     * notify_support_ticket_email and has a confirmed notification_email
     * (migrations/0045_add_operator_notification_preferences.sql). No board
     * context here (tickets aren't board-bound) — CommentNotificationMailer
     * falls back to the installation-wide SMTP config.
     */
    private function notifyOperatorsByEmail(string $title, string $body, string $linkPath): void
    {
        foreach ($this->users->findOperatorEmailRecipients('notify_support_ticket_email') as $recipientEmail) {
            $this->notificationMailer->send($recipientEmail, $title, $body, $linkPath, null);
        }
    }

    private function currentUserId(ServerRequestInterface $request): int
    {
        /** @var array<string, mixed>|null $user */
        $user = $request->getAttribute(AuthNMiddleware::ATTR_USER);
        return is_array($user) ? (int) ($user['id'] ?? 0) : 0;
    }

    private function actorId(ServerRequestInterface $request): int
    {
        return $this->currentUserId($request);
    }

    /** @param array<string, mixed> $payload */
    private function json(ResponseInterface $response, int $status, array $payload): ResponseInterface
    {
        $response->getBody()->write((string) json_encode($payload));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
