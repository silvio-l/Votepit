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
use Votepit\Persistence\NotificationRepository;
use Votepit\Persistence\SupportRequestRepository;
use Votepit\Support\SupportCategory;

/**
 * POST /{account}/admin/support               — submit a support request from the dashboard.
 * GET  /{account}/admin/support                — the caller's own account's tickets (with any reply).
 * GET  /operator/support                      — operator inbox, every account.
 * POST /operator/support/{id}/reply           — answer a ticket (sets status 'answered').
 * POST /operator/support/{id}/status          — set status directly (e.g. re-open/close).
 *
 * submit()/list() are account-scoped (AuthZ: accountAdmin — every
 * account_members role is owner|moderator, so this is effectively "any team
 * member of the account", matching the dashboard-contact use case, unlike
 * AbuseReportAction's anonymous public intake). A member only ever sees
 * their OWN account's tickets (findByIdForAccount/listForAccount), never
 * another tenant's — reply()/setStatus() are operator-only and read/write
 * unscoped (findByIdForOperator), mirroring AbuseReportAction's operator
 * tier split.
 *
 * Entirely in-app, no email anywhere (product decision, see
 * migrations/0024_add_notifications_remove_support_email.sql): a customer
 * never gives a contact email, and the return channel is an in-app
 * notification — operatorReply() creates one pointing back at the ticket.
 */
final readonly class SupportRequestAction
{
    private const ALLOWED_STATUSES = ['open', 'answered', 'closed'];

    public function __construct(
        private SupportRequestRepository $requests,
        private NotificationRepository $notifications,
        private AuditLogger $audit,
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

        foreach ($validator->validate($message, [
            new Assert\NotBlank(message: 'Please describe your request.'),
            new Assert\Length(min: 10, max: 8000, minMessage: 'Please describe it in a bit more detail (at least {{ limit }} characters).', maxMessage: 'The message must be at most {{ limit }} characters long.'),
        ]) as $e) {
            $fields['message'] = (string) $e->getMessage();
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

    public function operatorList(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $requests = array_map(
            $this->presentRequest(...),
            $this->requests->listAllForOperator(),
        );

        return $this->json($response, 200, ['requests' => $requests]);
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
            return $this->json($response, 404, ['error' => ['key' => 'not_found', 'message' => 'Request not found.']]);
        }

        $parsed = $request->getParsedBody();
        $reply  = trim((string) (is_array($parsed) ? ($parsed['reply'] ?? '') : ''));

        if ($reply === '') {
            return $this->json($response, 422, [
                'error' => ['key' => 'validation_error', 'message' => 'Please enter a reply.'],
            ]);
        }

        $actorId = $this->actorId($request);
        $this->requests->reply($requestId, $reply, $actorId);

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

    /**
     * @param  array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function presentRequest(array $row): array
    {
        return [
            'id'             => (int) $row['id'],
            'account_id'     => (int) $row['account_id'],
            'user_id'        => (int) $row['user_id'],
            'category'       => (string) $row['category'],
            'subject'        => (string) $row['subject'],
            'message'        => (string) $row['message'],
            'status'         => (string) $row['status'],
            'operator_reply' => $row['operator_reply'],
            'replied_by'     => $row['replied_by'] !== null ? (int) $row['replied_by'] : null,
            'replied_at'     => $row['replied_at'],
            'created_at'     => $row['created_at'],
            'updated_at'     => $row['updated_at'],
        ];
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
