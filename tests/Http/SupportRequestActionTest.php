<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Security\CsrfService;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Integration tests for POST/GET /admin/support (dashboard contact form)
 * and GET /operator/support + POST /operator/support/{id}/reply +
 * POST /operator/support/{id}/status (operator inbox).
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

    private function operator(): int
    {
        return $this->insertUser('operator-support@example.com', ['is_operator' => 1]);
    }

    /** A moderator (or owner) of the default account. */
    private function accountMember(string $email = 'member-support@example.com', string $role = 'moderator'): int
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

        $replyResponse = $app->handle($this->memberPost("/operator/support/{$requestId}/reply", $userId, ['reply' => 'Reply']));
        self::assertSame(403, $replyResponse->getStatusCode());
    }

    public function test_operator_replies_to_a_ticket_and_it_is_audit_logged(): void
    {
        $userId    = $this->accountMember();
        $requestId = $this->insertSupportRequest($this->defaultAccountId(), $userId);
        $operatorId = $this->operator();
        $app        = $this->createApp();

        $response = $app->handle($this->memberPost("/operator/support/{$requestId}/reply", $operatorId, ['reply' => 'Please clear the cache and try again.']));
        self::assertSame(200, $response->getStatusCode());

        $row = $this->conn->fetchAssociative('SELECT status, operator_reply, replied_by FROM support_requests WHERE id = :id', ['id' => $requestId]);
        self::assertIsArray($row);
        self::assertSame('answered', $row['status']);
        self::assertSame('Please clear the cache and try again.', $row['operator_reply']);
        self::assertSame($operatorId, (int) $row['replied_by']);

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
}
