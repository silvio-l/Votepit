<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Security\CsrfService;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Integration tests for POST/GET /admin/support + GET /admin/support/{id} +
 * POST /admin/support/{id}/reply (dashboard ticket thread) and
 * GET /operator/support + GET /operator/support/{id} +
 * POST /operator/support/{id}/reply + POST /operator/support/{id}/status
 * (operator inbox).
 */
final class SupportRequestActionTest extends IntegrationTestCase
{
    private function csrf(): CsrfService
    {
        return new CsrfService(str_repeat('a', 64), 3600, false);
    }

    /** @param array<string, mixed> $body */
    private function memberPost(string $path, int $userId, array $body): ServerRequestInterface
    {
        $csrf  = $this->csrf();
        $token = $csrf->generate();

        return (new ServerRequestFactory())
            ->createServerRequest('POST', $path)
            ->withCookieParams([
                'votepit_sess'      => $this->sessionCookie($userId),
                $csrf->cookieName() => $csrf->sign($token),
            ])
            ->withParsedBody(array_merge($body, ['_csrf' => $token]));
    }

    private function memberGet(string $path, int $userId): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('GET', $path)
            ->withCookieParams(['votepit_sess' => $this->sessionCookie($userId)]);
    }

    /** @param array<string, string> $query */
    private function memberGetQuery(string $path, int $userId, array $query): ServerRequestInterface
    {
        return $this->memberGet($path, $userId)->withQueryParams($query);
    }

    private function operator(): int
    {
        return $this->insertUser('operator-support@example.com', [
            'is_operator'     => 1,
            'totp_enabled_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * An admin (or owner) of the default account — handling support requests
     * is part of the 'admin' role's scope (AuthZMiddleware::accountAdmin()),
     * moderator is restricted to comment/idea moderation only and does not
     * pass it, see test_moderator_cannot_submit_a_ticket_returns_403 below.
     */
    private function accountMember(string $email = 'member-support@example.com', string $role = 'admin'): int
    {
        $userId = $this->insertUser($email);
        $this->insertAccountMember($this->defaultAccountId(), $userId, $role);
        return $userId;
    }

    public function test_account_member_submits_a_ticket_and_it_is_stored(): void
    {
        $userId = $this->accountMember();
        $app    = $this->createApp();

        $response = $app->handle($this->memberPost('/admin/support', $userId, [
            'category' => 'technical',
            'subject'  => 'Login does not work',
            'message'  => 'I have not been able to log in since this morning.',
        ]));

        self::assertSame(201, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertTrue($data['ok']);
        self::assertIsInt($data['id']);

        $row = $this->conn->fetchAssociative('SELECT * FROM support_requests WHERE id = :id', ['id' => $data['id']]);
        self::assertIsArray($row);
        self::assertSame('open', $row['status']);
        self::assertSame('technical', $row['category']);
        self::assertSame($this->defaultAccountId(), (int) $row['account_id']);
        self::assertSame($userId, (int) $row['user_id']);
    }

    public function test_moderator_cannot_submit_a_ticket_returns_403(): void
    {
        $userId = $this->accountMember('mod-support@example.com', 'moderator');
        $app    = $this->createApp();

        $response = $app->handle($this->memberPost('/admin/support', $userId, [
            'category' => 'technical',
            'subject'  => 'Test',
            'message'  => 'A message with enough characters for validation.',
        ]));

        self::assertSame(403, $response->getStatusCode());
    }

    public function test_non_member_cannot_submit_a_ticket(): void
    {
        $userId = $this->insertUser('outsider-support@example.com');
        $app    = $this->createApp();

        $response = $app->handle($this->memberPost('/admin/support', $userId, [
            'category' => 'technical',
            'subject'  => 'Test',
            'message'  => 'A message with enough characters for validation.',
        ]));

        self::assertSame(403, $response->getStatusCode());
    }

    public function test_invalid_category_is_rejected_with_422(): void
    {
        $userId = $this->accountMember();
        $app    = $this->createApp();

        $response = $app->handle($this->memberPost('/admin/support', $userId, [
            'category' => 'bogus',
            'subject'  => 'Test',
            'message'  => 'A message with enough characters for validation.',
        ]));

        self::assertSame(422, $response->getStatusCode());
    }

    public function test_too_short_message_is_rejected_with_422(): void
    {
        $userId = $this->accountMember();
        $app    = $this->createApp();

        $response = $app->handle($this->memberPost('/admin/support', $userId, [
            'category' => 'technical',
            'subject'  => 'Test',
            'message'  => 'too short',
        ]));

        self::assertSame(422, $response->getStatusCode());
    }

    public function test_member_sees_only_their_own_accounts_tickets(): void
    {
        $userId          = $this->accountMember();
        $otherAccountId  = $this->insertAccount();
        $otherUserId     = $this->insertUser('other-account-support@example.com');
        $this->insertAccountMember($otherAccountId, $otherUserId, 'owner');

        $this->insertSupportRequest($this->defaultAccountId(), $userId, ['subject' => 'My ticket']);
        $this->insertSupportRequest($otherAccountId, $otherUserId, ['subject' => 'Foreign ticket']);

        $app      = $this->createApp();
        $response = $app->handle($this->memberGet('/admin/support', $userId));
        $data     = json_decode((string) $response->getBody(), true);

        self::assertCount(1, $data['requests']);
        self::assertSame('My ticket', $data['requests'][0]['subject']);
    }

    public function test_non_operator_cannot_list_or_reply_to_tickets(): void
    {
        $userId    = $this->accountMember();
        $requestId = $this->insertSupportRequest($this->defaultAccountId(), $userId);

        $app = $this->createApp();

        $listResponse = $app->handle($this->memberGet('/operator/support', $userId));
        self::assertSame(403, $listResponse->getStatusCode());

        $threadResponse = $app->handle($this->memberGet("/operator/support/{$requestId}", $userId));
        self::assertSame(403, $threadResponse->getStatusCode());

        $replyResponse = $app->handle($this->memberPost("/operator/support/{$requestId}/reply", $userId, ['reply' => 'Reply']));
        self::assertSame(403, $replyResponse->getStatusCode());
    }

    public function test_account_member_can_view_their_ticket_thread_and_reply(): void
    {
        $userId    = $this->accountMember();
        $requestId = $this->insertSupportRequest($this->defaultAccountId(), $userId);
        $app       = $this->createApp();

        $threadResponse = $app->handle($this->memberGet("/admin/support/{$requestId}", $userId));
        self::assertSame(200, $threadResponse->getStatusCode());
        $threadData = json_decode((string) $threadResponse->getBody(), true);
        self::assertSame($requestId, $threadData['request']['id']);
        self::assertCount(1, $threadData['messages']);
        self::assertSame('customer', $threadData['messages'][0]['author_type']);

        $replyResponse = $app->handle($this->memberPost("/admin/support/{$requestId}/reply", $userId, ['message' => 'One more detail about my issue.']));
        self::assertSame(200, $replyResponse->getStatusCode());

        $rows = $this->conn->fetchAllAssociative('SELECT author_type FROM support_messages WHERE request_id = :id ORDER BY id', ['id' => $requestId]);
        self::assertCount(2, $rows);
        self::assertSame('customer', $rows[1]['author_type']);

        $ticket = $this->conn->fetchAssociative('SELECT status FROM support_requests WHERE id = :id', ['id' => $requestId]);
        self::assertIsArray($ticket);
        self::assertSame('open', $ticket['status']);
    }

    public function test_account_member_cannot_view_or_reply_to_another_accounts_ticket(): void
    {
        $userId         = $this->accountMember();
        $otherAccountId = $this->insertAccount();
        $otherUserId    = $this->insertUser('other-account-thread@example.com');
        $this->insertAccountMember($otherAccountId, $otherUserId, 'owner');
        $requestId = $this->insertSupportRequest($otherAccountId, $otherUserId);

        $app = $this->createApp();

        $threadResponse = $app->handle($this->memberGet("/admin/support/{$requestId}", $userId));
        self::assertSame(404, $threadResponse->getStatusCode());

        $replyResponse = $app->handle($this->memberPost("/admin/support/{$requestId}/reply", $userId, ['message' => 'A message with enough characters.']));
        self::assertSame(404, $replyResponse->getStatusCode());
    }

    public function test_operator_replies_to_a_ticket_and_it_is_audit_logged(): void
    {
        $userId    = $this->accountMember();
        $requestId = $this->insertSupportRequest($this->defaultAccountId(), $userId);
        $operatorId = $this->operator();
        $app        = $this->createApp();

        $response = $app->handle($this->memberPost("/operator/support/{$requestId}/reply", $operatorId, ['reply' => 'Please clear the cache and try again.']));
        self::assertSame(200, $response->getStatusCode());

        $row = $this->conn->fetchAssociative('SELECT status FROM support_requests WHERE id = :id', ['id' => $requestId]);
        self::assertIsArray($row);
        self::assertSame('answered', $row['status']);

        $messages = $this->conn->fetchAllAssociative('SELECT author_type, author_user_id, body FROM support_messages WHERE request_id = :id ORDER BY id', ['id' => $requestId]);
        self::assertCount(2, $messages);
        self::assertSame('operator', $messages[1]['author_type']);
        self::assertSame('Please clear the cache and try again.', $messages[1]['body']);
        self::assertSame($operatorId, (int) $messages[1]['author_user_id']);

        $threadResponse = $app->handle($this->memberGet("/operator/support/{$requestId}", $operatorId));
        self::assertSame(200, $threadResponse->getStatusCode());
        $threadData = json_decode((string) $threadResponse->getBody(), true);
        self::assertCount(2, $threadData['messages']);
        self::assertSame('operator', $threadData['messages'][1]['author_type']);

        $log = $this->readAuditLog();
        self::assertStringContainsString('operator.support_request.replied', $log);
        self::assertStringContainsString('"actor_tier":"operator"', $log);

        $notification = $this->conn->fetchAssociative(
            "SELECT * FROM notifications WHERE type = 'support_reply' AND account_id = :account_id",
            ['account_id' => $this->defaultAccountId()],
        );
        self::assertIsArray($notification);
        self::assertSame('account', $notification['scope']);
        self::assertSame('/admin/support', $notification['link_path']);

        $inbox = $app->handle($this->memberGet('/notifications', $userId));
        $inboxData = json_decode((string) $inbox->getBody(), true);
        self::assertCount(1, $inboxData['notifications']);
        self::assertSame('support_reply', $inboxData['notifications'][0]['type']);
        self::assertFalse($inboxData['notifications'][0]['is_read']);
    }

    public function test_submitting_a_ticket_notifies_operators_but_not_other_account_members(): void
    {
        $userId     = $this->accountMember();
        $otherMemberId = $this->accountMember('other-member-support@example.com', 'owner');
        $operatorId = $this->operator();
        $app        = $this->createApp();

        $response = $app->handle($this->memberPost('/admin/support', $userId, [
            'category' => 'technical',
            'subject'  => 'New request needs attention',
            'message'  => 'I have not been able to log in since this morning.',
        ]));
        self::assertSame(201, $response->getStatusCode());

        $notification = $this->conn->fetchAssociative(
            "SELECT * FROM notifications WHERE type = 'support_reply' AND scope = 'operator'",
        );
        self::assertIsArray($notification);
        self::assertNull($notification['account_id']);
        self::assertStringContainsString('New request needs attention', $notification['body']);

        $operatorInbox = $app->handle($this->memberGet('/notifications', $operatorId));
        $operatorData  = json_decode((string) $operatorInbox->getBody(), true);
        self::assertCount(1, $operatorData['notifications']);

        $memberInbox = $app->handle($this->memberGet('/notifications', $otherMemberId));
        $memberData  = json_decode((string) $memberInbox->getBody(), true);
        self::assertCount(0, $memberData['notifications']);
    }

    public function test_customer_reply_notifies_operators(): void
    {
        $userId    = $this->accountMember();
        $requestId = $this->insertSupportRequest($this->defaultAccountId(), $userId, ['subject' => 'Follow-up needed']);
        $operatorId = $this->operator();
        $app        = $this->createApp();

        $response = $app->handle($this->memberPost("/admin/support/{$requestId}/reply", $userId, ['message' => 'One more detail about my issue.']));
        self::assertSame(200, $response->getStatusCode());

        $notification = $this->conn->fetchAssociative(
            "SELECT * FROM notifications WHERE type = 'support_reply' AND scope = 'operator'",
        );
        self::assertIsArray($notification);
        self::assertSame("/operator/support/{$requestId}", $notification['link_path']);

        $operatorInbox = $app->handle($this->memberGet('/notifications', $operatorId));
        $operatorData  = json_decode((string) $operatorInbox->getBody(), true);
        self::assertCount(1, $operatorData['notifications']);
        self::assertSame('support_reply', $operatorData['notifications'][0]['type']);
    }

    public function test_empty_reply_is_rejected_with_422(): void
    {
        $userId     = $this->accountMember();
        $requestId  = $this->insertSupportRequest($this->defaultAccountId(), $userId);
        $operatorId = $this->operator();
        $app        = $this->createApp();

        $response = $app->handle($this->memberPost("/operator/support/{$requestId}/reply", $operatorId, ['reply' => '']));
        self::assertSame(422, $response->getStatusCode());
    }

    public function test_operator_sets_status(): void
    {
        $userId     = $this->accountMember();
        $requestId  = $this->insertSupportRequest($this->defaultAccountId(), $userId);
        $operatorId = $this->operator();
        $app        = $this->createApp();

        $response = $app->handle($this->memberPost("/operator/support/{$requestId}/status", $operatorId, ['status' => 'closed']));
        self::assertSame(200, $response->getStatusCode());

        $row = $this->conn->fetchAssociative('SELECT status FROM support_requests WHERE id = :id', ['id' => $requestId]);
        self::assertIsArray($row);
        self::assertSame('closed', $row['status']);
    }

    public function test_invalid_status_is_rejected_with_422(): void
    {
        $userId     = $this->accountMember();
        $requestId  = $this->insertSupportRequest($this->defaultAccountId(), $userId);
        $operatorId = $this->operator();
        $app        = $this->createApp();

        $response = $app->handle($this->memberPost("/operator/support/{$requestId}/status", $operatorId, ['status' => 'bogus']));
        self::assertSame(422, $response->getStatusCode());
    }

    public function test_operator_list_filters_by_status(): void
    {
        $userId    = $this->accountMember();
        $openId    = $this->insertSupportRequest($this->defaultAccountId(), $userId, ['subject' => 'Open one', 'status' => 'open']);
        $closedId  = $this->insertSupportRequest($this->defaultAccountId(), $userId, ['subject' => 'Closed one', 'status' => 'closed']);
        $operatorId = $this->operator();
        $app        = $this->createApp();

        $response = $app->handle($this->memberGetQuery('/operator/support', $operatorId, ['status' => 'closed']));
        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        $ids  = array_column($data['requests'], 'id');

        self::assertContains($closedId, $ids);
        self::assertNotContains($openId, $ids);
    }

    public function test_operator_list_includes_account_slug(): void
    {
        $userId     = $this->accountMember();
        $requestId  = $this->insertSupportRequest($this->defaultAccountId(), $userId, ['subject' => 'Needs account context']);
        $operatorId = $this->operator();
        $app        = $this->createApp();

        $response = $app->handle($this->memberGet('/operator/support', $operatorId));
        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        $row  = null;
        foreach ($data['requests'] as $r) {
            if ($r['id'] === $requestId) {
                $row = $r;
                break;
            }
        }

        self::assertNotNull($row);
        self::assertSame($this->defaultAccountSlug(), $row['account_slug']);
    }

    public function test_operator_list_filters_by_category(): void
    {
        $userId     = $this->accountMember();
        $billingId  = $this->insertSupportRequest($this->defaultAccountId(), $userId, ['category' => 'billing']);
        $techId     = $this->insertSupportRequest($this->defaultAccountId(), $userId, ['category' => 'technical']);
        $operatorId = $this->operator();
        $app        = $this->createApp();

        $response = $app->handle($this->memberGetQuery('/operator/support', $operatorId, ['category' => 'billing']));
        $data = json_decode((string) $response->getBody(), true);
        $ids  = array_column($data['requests'], 'id');

        self::assertContains($billingId, $ids);
        self::assertNotContains($techId, $ids);
    }

    public function test_operator_list_search_matches_subject_and_message_body(): void
    {
        $userId = $this->accountMember();
        $app    = $this->createApp();

        $bySubject = $this->insertSupportRequest($this->defaultAccountId(), $userId, ['subject' => 'Payment failed unexpectedly']);
        $byBody    = $this->insertSupportRequest($this->defaultAccountId(), $userId, ['subject' => 'Unrelated subject']);
        $this->insertSupportMessage($byBody, 'customer', $userId, 'The invoice download keeps failing for me.');
        $noMatch = $this->insertSupportRequest($this->defaultAccountId(), $userId, ['subject' => 'Something else entirely']);

        $operatorId = $this->operator();
        $response   = $app->handle($this->memberGetQuery('/operator/support', $operatorId, ['q' => 'failing']));
        $data       = json_decode((string) $response->getBody(), true);
        $ids        = array_column($data['requests'], 'id');

        self::assertContains($byBody, $ids);
        self::assertNotContains($bySubject, $ids);
        self::assertNotContains($noMatch, $ids);
    }

    public function test_operator_list_sorts_by_created_at_ascending(): void
    {
        $userId = $this->accountMember();
        $app    = $this->createApp();

        $older = $this->insertSupportRequest($this->defaultAccountId(), $userId, [
            'subject'    => 'Older ticket',
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ]);
        $newer = $this->insertSupportRequest($this->defaultAccountId(), $userId, [
            'subject'    => 'Newer ticket',
            'created_at' => '2026-01-02 00:00:00',
            'updated_at' => '2026-01-02 00:00:00',
        ]);

        $operatorId = $this->operator();
        $response   = $app->handle($this->memberGetQuery('/operator/support', $operatorId, ['sort' => 'created_at_asc']));
        $data       = json_decode((string) $response->getBody(), true);
        $ids        = array_column($data['requests'], 'id');
        $ourIds     = array_values(array_intersect($ids, [$older, $newer]));

        self::assertSame([$older, $newer], $ourIds);
    }

    public function test_operator_list_rejects_invalid_sort_with_422(): void
    {
        $operatorId = $this->operator();
        $app        = $this->createApp();

        $response = $app->handle($this->memberGetQuery('/operator/support', $operatorId, ['sort' => 'bogus']));
        self::assertSame(422, $response->getStatusCode());
    }

    public function test_operator_thread_includes_account_and_requester_context(): void
    {
        $accountId = $this->insertAccount(['slug' => 'acme-support-test', 'name' => 'Acme Inc.', 'plan' => 'pro']);
        $userId    = $this->insertUser('support-context@example.com', ['username' => 'jdoe']);
        $this->insertAccountMember($accountId, $userId, 'owner');
        $requestId = $this->insertSupportRequest($accountId, $userId);

        $operatorId = $this->operator();
        $app        = $this->createApp();

        $response = $app->handle($this->memberGet("/operator/support/{$requestId}", $operatorId));
        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);

        self::assertSame($accountId, $data['account']['id']);
        self::assertSame('acme-support-test', $data['account']['slug']);
        self::assertSame('Acme Inc.', $data['account']['name']);
        self::assertSame('pro', $data['account']['plan']);
        self::assertSame($userId, $data['requester']['id']);
        self::assertSame('jdoe', $data['requester']['username']);
        self::assertIsString($data['requester']['public_id'] ?? null);
        self::assertNotSame('', $data['requester']['public_id']);
    }

    public function test_submitting_a_ticket_emails_operators_who_opted_in(): void
    {
        $userId = $this->accountMember();
        $this->insertUser('operator-no-email-support@example.com', [
            'is_operator'     => 1,
            'totp_enabled_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
        $this->insertUser('operator-opted-in-support@example.com', [
            'is_operator'                => 1,
            'totp_enabled_at'            => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'notification_email'         => 'operator-opted-in-support@example.com',
            'notify_support_ticket_email' => 1,
        ]);
        $mailer = new \Votepit\Mail\InMemoryMailer();
        $app    = $this->createApp($mailer);

        $response = $app->handle($this->memberPost('/admin/support', $userId, [
            'category' => 'technical',
            'subject'  => 'Needs an email ping',
            'message'  => 'Please take a look at this as soon as you can.',
        ]));
        self::assertSame(201, $response->getStatusCode());

        self::assertCount(1, $mailer->sent);
        self::assertSame('operator-opted-in-support@example.com', $mailer->sent[0]['to']);
    }
}
