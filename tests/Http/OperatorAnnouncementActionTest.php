<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Security\CsrfService;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Integration tests for GET/POST /operator/announcements + DELETE
 * /operator/announcements/{id} — the operator-authored broadcast channel
 * (migrations/0024_add_notifications_remove_support_email.sql).
 */
final class OperatorAnnouncementActionTest extends IntegrationTestCase
{
    private function csrf(): CsrfService
    {
        return new CsrfService(str_repeat('a', 64), 3600, false);
    }

    /** @param array<string, mixed> $body */
    private function memberPost(string $path, int $userId, array $body, string $method = 'POST'): ServerRequestInterface
    {
        $csrf  = $this->csrf();
        $token = $csrf->generate();

        return (new ServerRequestFactory())
            ->createServerRequest($method, $path)
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
        return $this->insertUser('operator-announcements@example.com', ['is_operator' => 1]);
    }

    public function test_non_operator_cannot_create_an_announcement(): void
    {
        $userId = $this->insertUser('non-operator-announcements@example.com');
        $app    = $this->createApp();

        $response = $app->handle($this->memberPost('/operator/announcements', $userId, [
            'title' => 'New feature',
            'body'  => 'We have released a new feature.',
        ]));

        self::assertSame(403, $response->getStatusCode());
    }

    public function test_operator_creates_an_announcement_and_it_reaches_every_account(): void
    {
        $operatorId = $this->operator();
        $memberId   = $this->insertUser('announcement-recipient@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $memberId, 'moderator');

        $app = $this->createApp();

        $create = $app->handle($this->memberPost('/operator/announcements', $operatorId, [
            'title'     => 'New feature',
            'body'      => 'We have released a new feature.',
            'link_path' => '/changelog',
        ]));

        self::assertSame(201, $create->getStatusCode());

        $log = $this->readAuditLog();
        self::assertStringContainsString('operator.announcement.created', $log);

        $inbox = $app->handle($this->memberGet('/notifications', $memberId));
        $inboxData = json_decode((string) $inbox->getBody(), true);
        self::assertCount(1, $inboxData['notifications']);
        self::assertSame('New feature', $inboxData['notifications'][0]['title']);
        self::assertSame('/changelog', $inboxData['notifications'][0]['link_path']);
    }

    public function test_empty_title_is_rejected_with_422(): void
    {
        $operatorId = $this->operator();
        $app        = $this->createApp();

        $response = $app->handle($this->memberPost('/operator/announcements', $operatorId, [
            'title' => '',
            'body'  => 'Some text.',
        ]));

        self::assertSame(422, $response->getStatusCode());
    }

    public function test_external_link_path_is_rejected_with_422(): void
    {
        $operatorId = $this->operator();
        $app        = $this->createApp();

        $response = $app->handle($this->memberPost('/operator/announcements', $operatorId, [
            'title'     => 'Test',
            'body'      => 'Some text.',
            'link_path' => 'https://evil.example.com',
        ]));

        self::assertSame(422, $response->getStatusCode());
    }

    public function test_operator_deletes_an_announcement(): void
    {
        $operatorId     = $this->operator();
        $notificationId = $this->insertNotification(null, ['scope' => 'broadcast', 'type' => 'announcement', 'title' => 'Old announcement']);

        $app      = $this->createApp();
        $response = $app->handle($this->memberPost("/operator/announcements/{$notificationId}", $operatorId, [], 'DELETE'));

        self::assertSame(200, $response->getStatusCode());

        $row = $this->conn->fetchAssociative('SELECT * FROM notifications WHERE id = :id', ['id' => $notificationId]);
        self::assertFalse($row);
    }

    public function test_deleting_an_unknown_announcement_is_404(): void
    {
        $operatorId = $this->operator();
        $app        = $this->createApp();

        $response = $app->handle($this->memberPost('/operator/announcements/999999', $operatorId, [], 'DELETE'));
        self::assertSame(404, $response->getStatusCode());
    }
}
