<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Security\CsrfService;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Integration tests for GET /notifications + POST /notifications/{id}/read
 * (the in-app inbox — migrations/0024_add_notifications_remove_support_email.sql).
 */
final class NotificationActionTest extends IntegrationTestCase
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

    private function memberDelete(string $path, int $userId): ServerRequestInterface
    {
        $csrf  = $this->csrf();
        $token = $csrf->generate();

        return (new ServerRequestFactory())
            ->createServerRequest('DELETE', $path)
            ->withCookieParams([
                'votepit_sess'      => $this->sessionCookie($userId),
                $csrf->cookieName() => $csrf->sign($token),
            ])
            ->withParsedBody(['_csrf' => $token]);
    }

    public function test_anon_cannot_list_notifications(): void
    {
        $app      = $this->createApp();
        $response = $app->handle((new ServerRequestFactory())->createServerRequest('GET', '/notifications'));

        self::assertSame(401, $response->getStatusCode());
    }

    public function test_user_sees_own_account_notification_and_every_broadcast(): void
    {
        $userId = $this->insertUser('inbox-member@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $userId, 'moderator');

        $otherAccountId = $this->insertAccount();
        $otherUserId    = $this->insertUser('inbox-other@example.com');
        $this->insertAccountMember($otherAccountId, $otherUserId, 'owner');

        $this->insertNotification($this->defaultAccountId(), ['title' => 'Your request']);
        $this->insertNotification($otherAccountId, ['title' => 'Foreign request']);
        $this->insertNotification(null, ['scope' => 'broadcast', 'type' => 'announcement', 'title' => 'New feature']);

        $app      = $this->createApp();
        $response = $app->handle($this->memberGet('/notifications', $userId));
        $data     = json_decode((string) $response->getBody(), true);

        self::assertSame(200, $response->getStatusCode());
        $titles = array_column($data['notifications'], 'title');
        self::assertContains('Your request', $titles);
        self::assertContains('New feature', $titles);
        self::assertNotContains('Foreign request', $titles);
    }

    public function test_marking_a_notification_read_persists_across_requests(): void
    {
        $userId = $this->insertUser('inbox-read@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $userId, 'moderator');
        $notificationId = $this->insertNotification($this->defaultAccountId());

        $app = $this->createApp();

        $unread = $app->handle($this->memberGet('/notifications', $userId));
        $unreadData = json_decode((string) $unread->getBody(), true);
        self::assertFalse($unreadData['notifications'][0]['is_read']);

        $markResponse = $app->handle($this->memberPost("/notifications/{$notificationId}/read", $userId, []));
        self::assertSame(200, $markResponse->getStatusCode());

        $read = $app->handle($this->memberGet('/notifications', $userId));
        $readData = json_decode((string) $read->getBody(), true);
        self::assertTrue($readData['notifications'][0]['is_read']);
    }

    public function test_marking_an_unknown_notification_read_is_404(): void
    {
        $userId = $this->insertUser('inbox-404@example.com');
        $app    = $this->createApp();

        $response = $app->handle($this->memberPost('/notifications/999999/read', $userId, []));
        self::assertSame(404, $response->getStatusCode());
    }

    public function test_marking_another_accounts_notification_read_is_404_and_does_not_persist(): void
    {
        $userId = $this->insertUser('inbox-cross-tenant@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $userId, 'moderator');

        $otherAccountId  = $this->insertAccount();
        $notificationId  = $this->insertNotification($otherAccountId, ['title' => 'Foreign request']);

        $app      = $this->createApp();
        $response = $app->handle($this->memberPost("/notifications/{$notificationId}/read", $userId, []));

        self::assertSame(404, $response->getStatusCode());

        $ownerId = $this->insertUser('inbox-cross-tenant-owner@example.com');
        $this->insertAccountMember($otherAccountId, $ownerId, 'owner');
        $ownerView = $app->handle($this->memberGet('/notifications', $ownerId));
        $ownerData = json_decode((string) $ownerView->getBody(), true);
        self::assertFalse($ownerData['notifications'][0]['is_read']);
    }

    public function test_dismissing_a_notification_removes_it_from_the_users_inbox(): void
    {
        $userId = $this->insertUser('inbox-dismiss@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $userId, 'moderator');
        $notificationId = $this->insertNotification($this->defaultAccountId());

        $app = $this->createApp();

        $dismissResponse = $app->handle($this->memberDelete("/notifications/{$notificationId}", $userId));
        self::assertSame(200, $dismissResponse->getStatusCode());
        $dismissData = json_decode((string) $dismissResponse->getBody(), true);
        self::assertTrue($dismissData['ok']);

        $after     = $app->handle($this->memberGet('/notifications', $userId));
        $afterData = json_decode((string) $after->getBody(), true);
        self::assertSame([], $afterData['notifications']);
    }

    public function test_dismissing_is_idempotent(): void
    {
        $userId = $this->insertUser('inbox-dismiss-twice@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $userId, 'moderator');
        $notificationId = $this->insertNotification($this->defaultAccountId());

        $app = $this->createApp();

        $first  = $app->handle($this->memberDelete("/notifications/{$notificationId}", $userId));
        $second = $app->handle($this->memberDelete("/notifications/{$notificationId}", $userId));

        self::assertSame(200, $first->getStatusCode());
        self::assertSame(200, $second->getStatusCode());
    }

    public function test_dismissing_a_broadcast_does_not_affect_other_users(): void
    {
        $dismissingUserId = $this->insertUser('inbox-dismiss-broadcast@example.com');
        $otherUserId      = $this->insertUser('inbox-keep-broadcast@example.com');
        $notificationId   = $this->insertNotification(null, ['scope' => 'broadcast', 'type' => 'announcement', 'title' => 'New feature']);

        $app = $this->createApp();
        $app->handle($this->memberDelete("/notifications/{$notificationId}", $dismissingUserId));

        $dismissingView = $app->handle($this->memberGet('/notifications', $dismissingUserId));
        $dismissingData = json_decode((string) $dismissingView->getBody(), true);
        self::assertSame([], $dismissingData['notifications']);

        $otherView = $app->handle($this->memberGet('/notifications', $otherUserId));
        $otherData = json_decode((string) $otherView->getBody(), true);
        self::assertContains('New feature', array_column($otherData['notifications'], 'title'));
    }

    public function test_dismissing_an_unknown_notification_is_404(): void
    {
        $userId = $this->insertUser('inbox-dismiss-404@example.com');
        $app    = $this->createApp();

        $response = $app->handle($this->memberDelete('/notifications/999999', $userId));
        self::assertSame(404, $response->getStatusCode());
    }

    public function test_dismissing_another_accounts_notification_is_404_and_does_not_persist(): void
    {
        $userId = $this->insertUser('inbox-dismiss-cross-tenant@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $userId, 'moderator');

        $otherAccountId = $this->insertAccount();
        $notificationId = $this->insertNotification($otherAccountId, ['title' => 'Foreign request']);

        $app      = $this->createApp();
        $response = $app->handle($this->memberDelete("/notifications/{$notificationId}", $userId));

        self::assertSame(404, $response->getStatusCode());

        $ownerId = $this->insertUser('inbox-dismiss-cross-tenant-owner@example.com');
        $this->insertAccountMember($otherAccountId, $ownerId, 'owner');
        $ownerView = $app->handle($this->memberGet('/notifications', $ownerId));
        $ownerData = json_decode((string) $ownerView->getBody(), true);
        self::assertContains('Foreign request', array_column($ownerData['notifications'], 'title'));
    }
}
