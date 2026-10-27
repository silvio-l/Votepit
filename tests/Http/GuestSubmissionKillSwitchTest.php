<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Votepit\Config;
use Votepit\Tests\Support\GuestIdeaTestCase;

/**
 * Installation-wide kill switch for guest idea submission
 * (Config::$guestSubmissionEnabled): with it off, a board whose flag is on
 * (set before the switch went off, or straight in the DB) must behave exactly
 * like a board with guests off on every guest path, and owners cannot switch
 * guests on.
 */
final class GuestSubmissionKillSwitchTest extends GuestIdeaTestCase
{
    public function test_every_guest_path_is_closed_while_the_installation_switch_is_off(): void
    {
        $boardId = $this->guestBoard('k-on');
        $member  = $this->insertUser('member@example.com');
        $this->seedIdea($boardId, $member, 'Dark mode for the dashboard');
        $app = $this->guestApp($this->guestConfig(['guest_submission_enabled' => false]));

        $submit = $this->submitAsGuest($app, '/k-on/ideas');
        self::assertSame(401, $submit->getStatusCode());
        self::assertSame([], $submit->getHeader('Set-Cookie'));
        self::assertSame(1, $this->ideaCount());

        self::assertSame(404, $app->handle($this->request('GET', '/k-on/ideas/guest-mine'))->getStatusCode());

        $dupes = $app->handle($this->request('GET', '/k-on/ideas/search-duplicates?title=' . rawurlencode('Dark mode for the dashboard')));
        self::assertNotSame(200, $dupes->getStatusCode());

        // The SPA must not render a dead guest form.
        self::assertFalse($this->json($app->handle($this->request('GET', '/k-on')))['board']['anonymous_submission_available']);
        self::assertFalse($this->json($app->handle($this->request('GET', '/k-on/ideas/new')))['board']['anonymous_submission_available']);
    }

    public function test_owner_cannot_switch_guests_on_while_the_installation_switch_is_off(): void
    {
        $boardId = $this->insertBoard('k-deny');
        $owner   = $this->owner();
        $app     = $this->guestApp($this->guestConfig(['guest_submission_enabled' => false]));

        $response = $app->handle($this->request('POST', '/admin/boards/k-deny/moderation', ['action' => 'anonymous_submission', 'anonymous_submission_enabled' => '1'], userId: $owner));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('anonymous_submission_unavailable', $this->errorKey($response));
        self::assertSame(0, (int) $this->conn->fetchOne('SELECT anonymous_submission_enabled FROM boards WHERE id = :id', ['id' => $boardId]));
        self::assertFalse($this->json($app->handle($this->request('GET', '/admin/boards/k-deny/moderation', userId: $owner)))['anonymous_submission']['instance_allowed']);
    }

    public function test_owner_can_still_switch_guests_off_while_the_installation_switch_is_off(): void
    {
        $boardId = $this->guestBoard('k-off');
        $app     = $this->guestApp($this->guestConfig(['guest_submission_enabled' => false]));

        $response = $app->handle($this->request('POST', '/admin/boards/k-off/moderation', ['action' => 'anonymous_submission', 'anonymous_submission_enabled' => '0'], userId: $this->owner()));

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(0, (int) $this->conn->fetchOne('SELECT anonymous_submission_enabled FROM boards WHERE id = :id', ['id' => $boardId]));
    }

    public function test_cloud_routing_without_the_key_keeps_guests_locked(): void
    {
        $config = Config::fromArray($this->testConfigArray(['routing_mode' => 'cloud']));

        self::assertFalse($config->guestSubmissionEnabled);
    }

    private function owner(): int
    {
        $id = $this->insertUser('owner-' . bin2hex(random_bytes(3)) . '@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $id, 'owner');

        return $id;
    }
}
