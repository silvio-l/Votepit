<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Votepit\Mail\InMemoryMailer;
use Votepit\Persistence\UserErasureRepository;
use Votepit\Persistence\UserRepository;
use Votepit\Security\AnonymousAuthorHasher;
use Votepit\Security\AnonymousVoterHasher;
use Votepit\Tests\Support\GuestIdeaTestCase;
use Votepit\Tests\Support\RecordingDuplicateConfirmer;
use Votepit\Tests\Support\StubExtension;

/**
 * Guest idea submission around the submit path (ADR 0006): the device's
 * "my ideas" list and withdraw (§7), the bulk reject (§5a), the duplicate
 * hint for guests (§5b), the board settings switch (§8/§9), the public
 * availability flag and the guest-placeholder special cases (§1) —
 * Testmatrix points 5–8 plus the review additions.
 */
final class GuestIdeaManagementTest extends GuestIdeaTestCase
{
    // -------------------------------------------------------------------------
    // §7 "My ideas on this device"
    // -------------------------------------------------------------------------

    public function test_guest_mine_is_404_while_the_switch_is_off_or_the_board_private(): void
    {
        $this->insertBoard('m-off');
        $this->guestBoard('m-private', ['visibility' => 'private', 'is_default' => 0]);
        $app = $this->guestApp();

        self::assertSame(404, $app->handle($this->request('GET', '/m-off/ideas/guest-mine'))->getStatusCode());
        self::assertSame(404, $app->handle($this->request('GET', '/m-private/ideas/guest-mine'))->getStatusCode());
    }

    public function test_guest_mine_without_cookie_is_an_empty_list(): void
    {
        $this->guestBoard('m-empty');

        $response = $this->guestApp()->handle($this->request('GET', '/m-empty/ideas/guest-mine'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['ideas' => []], $this->json($response));
    }

    public function test_guest_mine_lists_only_this_devices_ideas_with_state_and_reason(): void
    {
        $this->guestBoard('m-list');
        $app     = $this->guestApp();
        $ownerId = $this->owner();

        $first = $this->submitAsGuest($app, '/m-list/ideas', 'Pending one');
        $raw   = $this->setCookies($first)['vp_anon_author'];
        $mine  = ['vp_anon_author' => $raw];
        $this->submitAsGuest($app, '/m-list/ideas', 'Will be approved', cookies: $mine);
        $this->submitAsGuest($app, '/m-list/ideas', 'Will be rejected', cookies: $mine, ip: '198.51.100.9');
        // Another device on the same board.
        $this->submitAsGuest($app, '/m-list/ideas', 'Someone else', ip: '198.51.100.10');

        $approveId = $this->ideaIdByTitle('Will be approved');
        $rejectId  = $this->ideaIdByTitle('Will be rejected');
        self::assertSame(200, $app->handle($this->request('POST', '/admin/boards/m-list/queue/approve', ['ids' => [$approveId]], userId: $ownerId))->getStatusCode());
        self::assertSame(200, $app->handle($this->request('POST', '/m-list/ideas/' . $rejectId . '/moderate', ['reason_code' => 'illegal_privacy'], userId: $ownerId))->getStatusCode());

        $ideas = $this->json($app->handle($this->request('GET', '/m-list/ideas/guest-mine', cookies: $mine)))['ideas'];

        $byTitle = array_column($ideas, null, 'title');
        self::assertSame(['Will be rejected', 'Will be approved', 'Pending one'], array_column($ideas, 'title'));
        self::assertSame('pending', $byTitle['Pending one']['moderation_state']);
        self::assertFalse($byTitle['Pending one']['rejected']);
        self::assertSame('published', $byTitle['Will be approved']['moderation_state']);
        self::assertTrue($byTitle['Will be rejected']['rejected']);
        self::assertSame('illegal_privacy', $byTitle['Will be rejected']['reason_code']);
        self::assertNull($byTitle['Pending one']['reason_code']);
        self::assertArrayNotHasKey('anon_author_token_hash', $ideas[0]);
    }

    public function test_guest_mine_is_capped_at_fifty(): void
    {
        $boardId = $this->guestBoard('m-cap');
        $guestId = (new UserRepository($this->conn))->findOrCreateGuestPlaceholder();
        $raw     = AnonymousVoterHasher::generateToken();
        $hash    = (new AnonymousAuthorHasher(self::identityServerKey()))->hashToken($this->defaultAccountId(), $raw);
        for ($i = 0; $i < 55; $i++) {
            $this->seedIdea($boardId, $guestId, 'Seeded ' . $i, ['moderation_state' => 'pending', 'anon_author_token_hash' => $hash]);
        }

        $ideas = $this->json($this->guestApp()->handle($this->request('GET', '/m-cap/ideas/guest-mine', cookies: ['vp_anon_author' => $raw])))['ideas'];

        self::assertCount(50, $ideas);
    }

    // -------------------------------------------------------------------------
    // §7 Withdraw
    // -------------------------------------------------------------------------

    public function test_guest_withdraws_own_pending_idea(): void
    {
        $this->guestBoard('w-own');
        $app  = $this->guestApp();
        $res  = $this->submitAsGuest($app, '/w-own/ideas');
        $id   = (int) $this->json($res)['id'];
        $mine = ['vp_anon_author' => $this->setCookies($res)['vp_anon_author']];

        $response = $app->handle($this->request('POST', '/w-own/ideas/' . $id . '/guest-withdraw', [], $mine));

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(['ok' => true], $this->json($response));
        self::assertSame(0, $this->ideaCount(), 'Withdraw is a hard delete.');
        self::assertStringContainsString('idea.guest_withdrawn', $this->readAuditLog());
        self::assertSame([], $this->json($app->handle($this->request('GET', '/w-own/ideas/guest-mine', cookies: $mine)))['ideas']);
    }

    public function test_withdraw_after_approval_is_404(): void
    {
        $this->guestBoard('w-approved');
        $app  = $this->guestApp();
        $res  = $this->submitAsGuest($app, '/w-approved/ideas');
        $id   = (int) $this->json($res)['id'];
        $mine = ['vp_anon_author' => $this->setCookies($res)['vp_anon_author']];
        $app->handle($this->request('POST', '/admin/boards/w-approved/queue/approve', ['ids' => [$id]], userId: $this->owner()));

        $response = $app->handle($this->request('POST', '/w-approved/ideas/' . $id . '/guest-withdraw', [], $mine));

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(1, $this->ideaCount());
    }

    public function test_withdraw_with_foreign_cookie_no_cookie_or_member_idea_is_404(): void
    {
        $boardId = $this->guestBoard('w-foreign');
        $app     = $this->guestApp();
        $res     = $this->submitAsGuest($app, '/w-foreign/ideas');
        $id      = (int) $this->json($res)['id'];
        $member  = $this->insertUser('member@example.com');
        $memberIdea = $this->seedIdea($boardId, $member, 'Member pending', ['moderation_state' => 'pending']);
        $mine    = ['vp_anon_author' => $this->setCookies($res)['vp_anon_author']];

        $other = ['vp_anon_author' => AnonymousVoterHasher::generateToken()];
        self::assertSame(404, $app->handle($this->request('POST', '/w-foreign/ideas/' . $id . '/guest-withdraw', [], $other))->getStatusCode());
        self::assertSame(404, $app->handle($this->request('POST', '/w-foreign/ideas/' . $id . '/guest-withdraw'))->getStatusCode());
        self::assertSame(404, $app->handle($this->request('POST', '/w-foreign/ideas/' . $memberIdea . '/guest-withdraw', [], $mine))->getStatusCode());
        self::assertSame(404, $app->handle($this->request('POST', '/w-foreign/ideas/999999/guest-withdraw', [], $mine))->getStatusCode());
        self::assertSame(2, $this->ideaCount());
    }

    public function test_withdraw_is_404_when_the_switch_is_turned_off(): void
    {
        $boardId = $this->guestBoard('w-switched');
        $app     = $this->guestApp();
        $res     = $this->submitAsGuest($app, '/w-switched/ideas');
        $id      = (int) $this->json($res)['id'];
        $this->conn->update('boards', ['anonymous_submission_enabled' => 0], ['id' => $boardId]);

        $response = $app->handle($this->request('POST', '/w-switched/ideas/' . $id . '/guest-withdraw', [], ['vp_anon_author' => $this->setCookies($res)['vp_anon_author']]));

        self::assertSame(404, $response->getStatusCode());
    }

    public function test_withdraw_token_bucket(): void
    {
        $this->guestBoard('w-bucket');
        $app  = $this->guestApp($this->guestConfig(['rate_limits' => ['idea:guest-withdraw:token' => ['limit' => 2, 'window' => 3600]]]));
        $mine = ['vp_anon_author' => AnonymousVoterHasher::generateToken()];

        self::assertSame(404, $app->handle($this->request('POST', '/w-bucket/ideas/1/guest-withdraw', [], $mine))->getStatusCode());
        self::assertSame(404, $app->handle($this->request('POST', '/w-bucket/ideas/2/guest-withdraw', [], $mine))->getStatusCode());
        $limited = $app->handle($this->request('POST', '/w-bucket/ideas/3/guest-withdraw', [], $mine));
        self::assertSame(429, $limited->getStatusCode());
        self::assertSame('rate_limited', $this->errorKey($limited));
    }

    // -------------------------------------------------------------------------
    // §5a Bulk reject
    // -------------------------------------------------------------------------

    public function test_bulk_reject_needs_confirmation(): void
    {
        $this->guestBoard('b-confirm');
        $app = $this->guestApp();
        $this->submitAsGuest($app, '/b-confirm/ideas');

        $response = $app->handle($this->request('POST', '/admin/boards/b-confirm/moderation/guest-reject-all', [], userId: $this->owner()));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('confirmation_required', $this->errorKey($response));
        self::assertSame(1, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM ideas WHERE deleted_at IS NULL'));
    }

    public function test_bulk_reject_rejects_only_pending_guest_ideas_of_this_board(): void
    {
        $boardId = $this->guestBoard('b-all');
        $otherId = $this->guestBoard('b-other', ['is_default' => 0]);
        $app     = $this->guestApp();
        $ownerId = $this->owner();
        $mailer  = new InMemoryMailer();
        $this->submitAsGuest($app, '/b-all/ideas', 'Guest one');
        $this->submitAsGuest($app, '/b-all/ideas', 'Guest two', ip: '198.51.100.20');
        $this->submitAsGuest($app, '/b-other/ideas', 'Guest elsewhere', ip: '198.51.100.21');
        $member      = $this->insertUser('member@example.com');
        $memberIdea  = $this->seedIdea($boardId, $member, 'Member pending', ['moderation_state' => 'pending']);
        $guestPublic = $this->ideaIdByTitle('Guest two');
        $this->conn->update('ideas', ['moderation_state' => 'published'], ['id' => $guestPublic]);

        $response = $app->handle($this->request('POST', '/admin/boards/b-all/moderation/guest-reject-all', ['confirm' => true], userId: $ownerId));

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(['ok' => true, 'rejected' => 1], $this->json($response));
        self::assertNotNull($this->conn->fetchOne('SELECT deleted_at FROM ideas WHERE id = :id', ['id' => $this->ideaIdByTitle('Guest one')]));
        self::assertNull($this->conn->fetchOne('SELECT deleted_at FROM ideas WHERE id = :id', ['id' => $guestPublic]));
        self::assertNull($this->conn->fetchOne('SELECT deleted_at FROM ideas WHERE id = :id', ['id' => $memberIdea]));
        self::assertNull($this->conn->fetchOne('SELECT deleted_at FROM ideas WHERE id = :id', ['id' => $this->ideaIdByTitle('Guest elsewhere')]));
        self::assertSame('community_rule', $this->conn->fetchOne('SELECT reason_code FROM content_moderation_actions'));
        self::assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM account_member_standing'), 'No strike for the placeholder.');
        self::assertSame(0, $mailer->count());
        $log = $this->readAuditLog();
        self::assertStringContainsString('idea.guest_bulk_rejected', $log);
        self::assertStringContainsString('"count":1', $log);
        self::assertSame($otherId, (int) $this->conn->fetchOne('SELECT board_id FROM ideas WHERE id = :id', ['id' => $this->ideaIdByTitle('Guest elsewhere')]));
    }

    public function test_bulk_reject_authz_and_cross_tenant(): void
    {
        $accountB = $this->insertAccount(['slug' => 'acct-bb']);
        $this->guestBoard('b-a', ['is_default' => 0]);
        $this->guestBoard('b-b', ['account_id' => $accountB, 'is_default' => 0]);
        $ownerA   = $this->owner();
        $ownerB   = $this->insertUser('owner-b@example.com');
        $this->insertAccountMember($accountB, $ownerB, 'owner');
        $stranger = $this->insertUser('stranger@example.com');
        $app      = $this->guestApp($this->guestConfig(['routing_mode' => 'cloud']));
        $prefixA  = '/' . $this->defaultAccountSlug();
        $this->submitAsGuest($app, '/acct-bb/b-b/ideas');

        // anon → 401, non-member → 403, foreign board under own prefix → 404.
        self::assertSame(401, $app->handle($this->request('POST', '/acct-bb/admin/boards/b-b/moderation/guest-reject-all', ['confirm' => true]))->getStatusCode());
        self::assertSame(403, $app->handle($this->request('POST', '/acct-bb/admin/boards/b-b/moderation/guest-reject-all', ['confirm' => true], userId: $stranger))->getStatusCode());
        self::assertSame(404, $app->handle($this->request('POST', $prefixA . '/admin/boards/b-b/moderation/guest-reject-all', ['confirm' => true], userId: $ownerA))->getStatusCode());
        self::assertSame(1, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM ideas WHERE deleted_at IS NULL'));

        $ok = $app->handle($this->request('POST', '/acct-bb/admin/boards/b-b/moderation/guest-reject-all', ['confirm' => '1'], userId: $ownerB));
        self::assertSame(['ok' => true, 'rejected' => 1], $this->json($ok));
    }

    // -------------------------------------------------------------------------
    // Queue labels
    // -------------------------------------------------------------------------

    public function test_queue_marks_guest_ideas_and_counts_them(): void
    {
        $boardId = $this->guestBoard('q-label', ['premoderation' => 'all']);
        $app     = $this->guestApp();
        $this->submitAsGuest($app, '/q-label/ideas', 'From a guest');
        $member = $this->insertUser('member@example.com');
        $this->seedIdea($boardId, $member, 'From a member', ['moderation_state' => 'pending']);

        $data = $this->json($app->handle($this->request('GET', '/admin/boards/q-label/queue', userId: $this->owner())));

        self::assertSame(2, $data['count']);
        self::assertSame(1, $data['guest_count']);
        self::assertSame(['From a guest' => 'guest', 'From a member' => 'member'], array_column($data['ideas'], 'author_type', 'title'));
    }

    // -------------------------------------------------------------------------
    // §8/§9 Board settings
    // -------------------------------------------------------------------------

    public function test_settings_get_reports_switch_and_preconditions(): void
    {
        $this->insertBoard('s-get');

        $data = $this->json($this->guestApp()->handle($this->request('GET', '/admin/boards/s-get/moderation', userId: $this->owner())));

        self::assertSame(['enabled' => false, 'instance_allowed' => true, 'turnstile_configured' => true, 'plan_allowed' => true], $data['anonymous_submission']);
    }

    public function test_switching_on_raises_premoderation_off_to_anonymous_and_audits(): void
    {
        $boardId = $this->insertBoard('s-on', ['premoderation' => 'off']);

        $response = $this->guestApp()->handle($this->request('POST', '/admin/boards/s-on/moderation', ['action' => 'anonymous_submission', 'anonymous_submission_enabled' => '1'], userId: $this->owner()));

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $board = $this->conn->fetchAssociative('SELECT anonymous_submission_enabled, premoderation FROM boards WHERE id = :id', ['id' => $boardId]);
        self::assertIsArray($board);
        self::assertSame(1, (int) $board['anonymous_submission_enabled']);
        self::assertSame('anonymous', $board['premoderation']);
        self::assertStringContainsString('board.anon_submission.enabled', $this->readAuditLog());
    }

    public function test_switching_on_keeps_the_stricter_all_mode(): void
    {
        $boardId = $this->insertBoard('s-all', ['premoderation' => 'all']);

        $this->guestApp()->handle($this->request('POST', '/admin/boards/s-all/moderation', ['action' => 'anonymous_submission', 'anonymous_submission_enabled' => '1'], userId: $this->owner()));

        self::assertSame('all', $this->conn->fetchOne('SELECT premoderation FROM boards WHERE id = :id', ['id' => $boardId]));
    }

    public function test_switching_on_without_turnstile_or_on_a_plan_without_it_is_422(): void
    {
        $boardId = $this->insertBoard('s-deny');
        $owner   = $this->owner();
        $body    = ['action' => 'anonymous_submission', 'anonymous_submission_enabled' => '1'];

        $noCaptcha = $this->guestApp($this->guestConfig(turnstile: false))->handle($this->request('POST', '/admin/boards/s-deny/moderation', $body, userId: $owner));
        self::assertSame(422, $noCaptcha->getStatusCode());
        self::assertSame('captcha_unavailable', $this->errorKey($noCaptcha));

        $this->setAccountPlan($this->defaultAccountId(), 'free');
        $app  = $this->guestApp();
        $plan = $app->handle($this->request('POST', '/admin/boards/s-deny/moderation', $body, userId: $owner));
        self::assertSame(422, $plan->getStatusCode());
        self::assertSame('plan_limit_anonymous_submission', $this->errorKey($plan));
        self::assertSame(0, (int) $this->conn->fetchOne('SELECT anonymous_submission_enabled FROM boards WHERE id = :id', ['id' => $boardId]));
        self::assertFalse($this->json($app->handle($this->request('GET', '/admin/boards/s-deny/moderation', userId: $owner)))['anonymous_submission']['plan_allowed']);
    }

    public function test_premoderation_off_while_guests_are_on_needs_confirmation(): void
    {
        $boardId = $this->guestBoard('s-confirm');
        $app     = $this->guestApp();
        $owner   = $this->owner();

        $refused = $app->handle($this->request('POST', '/admin/boards/s-confirm/moderation', ['action' => 'premoderation', 'premoderation' => 'off'], userId: $owner));
        self::assertSame(422, $refused->getStatusCode());
        self::assertSame('confirmation_required', $this->errorKey($refused));
        self::assertSame('anonymous', $this->conn->fetchOne('SELECT premoderation FROM boards WHERE id = :id', ['id' => $boardId]));

        $ok = $app->handle($this->request('POST', '/admin/boards/s-confirm/moderation', ['action' => 'premoderation', 'premoderation' => 'off', 'confirm_guest_public' => true], userId: $owner));
        self::assertSame(200, $ok->getStatusCode());
        self::assertSame('off', $this->conn->fetchOne('SELECT premoderation FROM boards WHERE id = :id', ['id' => $boardId]));
        self::assertStringContainsString('board.anon_submission.premoderation_disabled', $this->readAuditLog());

        // Now a guest idea is public at once.
        $res = $this->submitAsGuest($app, '/s-confirm/ideas');
        self::assertSame('published', $this->json($res)['moderation_state']);

        // Off and on again → the review floor is back.
        $app->handle($this->request('POST', '/admin/boards/s-confirm/moderation', ['action' => 'anonymous_submission', 'anonymous_submission_enabled' => '0'], userId: $owner));
        self::assertStringContainsString('board.anon_submission.disabled', $this->readAuditLog());
        $app->handle($this->request('POST', '/admin/boards/s-confirm/moderation', ['action' => 'anonymous_submission', 'anonymous_submission_enabled' => '1'], userId: $owner));
        self::assertSame('anonymous', $this->conn->fetchOne('SELECT premoderation FROM boards WHERE id = :id', ['id' => $boardId]));
    }

    public function test_premoderation_off_without_guests_needs_no_confirmation(): void
    {
        $boardId = $this->insertBoard('s-plain', ['premoderation' => 'all']);

        $response = $this->guestApp()->handle($this->request('POST', '/admin/boards/s-plain/moderation', ['action' => 'premoderation', 'premoderation' => 'off'], userId: $this->owner()));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('off', $this->conn->fetchOne('SELECT premoderation FROM boards WHERE id = :id', ['id' => $boardId]));
        self::assertStringNotContainsString('premoderation_disabled', $this->readAuditLog());
    }

    // -------------------------------------------------------------------------
    // Public availability flag (§8)
    // -------------------------------------------------------------------------

    public function test_board_home_and_new_form_report_availability(): void
    {
        $this->guestBoard('a-on');
        $this->insertBoard('a-off', ['is_default' => 0]);
        $app = $this->guestApp();

        self::assertTrue($this->json($app->handle($this->request('GET', '/a-on')))['board']['anonymous_submission_available']);
        self::assertFalse($this->json($app->handle($this->request('GET', '/a-off')))['board']['anonymous_submission_available']);
        self::assertTrue($this->json($app->handle($this->request('GET', '/a-on/ideas/new')))['board']['anonymous_submission_available']);
        self::assertFalse($this->json($app->handle($this->request('GET', '/a-off/ideas/new')))['board']['anonymous_submission_available']);

        // The guest form needs the (public) site key to render the widget.
        self::assertSame('site-123', $this->json($app->handle($this->request('GET', '/a-on/ideas/new')))['board']['turnstile_site_key']);
        self::assertArrayNotHasKey('turnstile_site_key', $this->json($app->handle($this->request('GET', '/a-off/ideas/new')))['board']);

        // Flag on, Turnstile removed later → not available (no dead form).
        $noCaptcha = $this->guestApp($this->guestConfig(turnstile: false));
        self::assertFalse($this->json($noCaptcha->handle($this->request('GET', '/a-on')))['board']['anonymous_submission_available']);
    }

    // -------------------------------------------------------------------------
    // §5b Duplicate hint for guests
    // -------------------------------------------------------------------------

    public function test_guest_duplicate_search_returns_published_titles_only(): void
    {
        $boardId = $this->guestBoard('d-on');
        $member  = $this->insertUser('member@example.com');
        $public  = $this->seedIdea($boardId, $member, 'Dark mode for the dashboard');
        $this->seedIdea($boardId, $member, 'Dark mode for the dashboard pending', ['moderation_state' => 'pending']);

        $response = $this->guestApp()->handle($this->request('GET', '/d-on/ideas/search-duplicates?title=' . rawurlencode('Dark mode for the dashboard')));

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame([$public], array_column($this->json($response)['candidates'], 'id'));
    }

    public function test_guest_duplicate_search_never_reaches_the_confirmer(): void
    {
        $boardId   = $this->guestBoard('d-confirm');
        $member    = $this->insertUser('member@example.com');
        $this->seedIdea($boardId, $member, 'Dark mode for the dashboard');
        $confirmer = new RecordingDuplicateConfirmer([]);
        $app       = $this->createApp(
            extensions: [new StubExtension(['duplicate_confirmer' => $confirmer])],
            config: $this->guestConfig(),
            turnstileHttpClient: $this->turnstile,
        );

        $response = $app->handle($this->request('GET', '/d-confirm/ideas/search-duplicates?confirm=1&title=' . rawurlencode('Dark mode for the dashboard')));

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $this->json($response)['candidates']);
        self::assertSame([], $confirmer->calls);
    }

    public function test_guest_duplicate_search_is_the_unchanged_401_when_switched_off(): void
    {
        $this->insertBoard('d-off');

        $response = $this->guestApp()->handle($this->request('GET', '/d-off/ideas/search-duplicates?title=anything'));

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('Access denied.', (string) $response->getBody());
        self::assertSame([], $response->getHeader('Content-Type'));
    }

    public function test_guest_duplicate_search_has_its_own_ip_bucket(): void
    {
        $this->guestBoard('d-bucket');
        $app = $this->guestApp($this->guestConfig(['rate_limits' => ['idea:dupes:anon:ip' => ['limit' => 1, 'window' => 3600]]]));

        self::assertSame(200, $app->handle($this->request('GET', '/d-bucket/ideas/search-duplicates?title=abc'))->getStatusCode());
        self::assertSame(429, $app->handle($this->request('GET', '/d-bucket/ideas/search-duplicates?title=abc'))->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // §1 Guest placeholder special cases
    // -------------------------------------------------------------------------

    public function test_moderating_a_guest_idea_creates_no_strike_and_no_mail(): void
    {
        $this->guestBoard('p-mod');
        $mailer = new InMemoryMailer();
        $app    = $this->guestApp(mailer: $mailer);
        $id     = (int) $this->json($this->submitAsGuest($app, '/p-mod/ideas'))['id'];

        $response = $app->handle($this->request('POST', '/p-mod/ideas/' . $id . '/moderate', ['reason_code' => 'community_rule'], userId: $this->owner()));

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM account_member_standing'));
        self::assertSame(0, $mailer->count());
    }

    public function test_guest_placeholder_cannot_be_blocked(): void
    {
        $this->guestBoard('p-block');
        $guestId = (new UserRepository($this->conn))->findOrCreateGuestPlaceholder();

        $response = $this->guestApp()->handle($this->request('POST', '/admin/boards/p-block/block', ['user_id' => $guestId, 'blocked' => '1', 'scope' => 'account'], userId: $this->owner()));

        self::assertSame(422, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('guest_not_blockable', $this->errorKey($response));
        self::assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM blocked_users'));
    }

    public function test_guest_placeholder_cannot_be_erased(): void
    {
        $users   = new UserRepository($this->conn);
        $guestId = $users->findOrCreateGuestPlaceholder();

        $this->expectException(\LogicException::class);
        (new UserErasureRepository($this->conn))->erase($guestId, $users->findOrCreateDeletedPlaceholder());
    }

    public function test_guest_profile_is_flagged_and_has_no_stats(): void
    {
        $this->guestBoard('p-profile');
        $guestId = (new UserRepository($this->conn))->findOrCreateGuestPlaceholder();
        $this->conn->update('users', ['profile_visible' => 1], ['id' => $guestId]);

        $data = $this->json($this->guestApp()->handle($this->request('GET', '/members/' . $guestId . '/profile')));

        self::assertTrue($data['guest']);
        self::assertFalse($data['visible']);
        self::assertArrayNotHasKey('ideas_submitted', $data);
    }

    public function test_member_profile_payload_has_no_guest_key(): void
    {
        $member = $this->insertUser('member@example.com');

        $data = $this->json($this->guestApp()->handle($this->request('GET', '/members/' . $member . '/profile')));

        self::assertArrayNotHasKey('guest', $data);
    }

    public function test_guest_placeholder_never_collects_notifications(): void
    {
        $this->guestBoard('p-notify');
        $app = $this->guestApp();
        $id  = (int) $this->json($this->submitAsGuest($app, '/p-notify/ideas'))['id'];
        $owner = $this->owner();

        $app->handle($this->request('POST', '/admin/boards/p-notify/queue/approve', ['ids' => [$id]], userId: $owner));
        $app->handle($this->request('POST', '/p-notify/ideas/' . $id . '/comments', ['body' => 'Thanks, good point.'], userId: $owner));

        $guestId = (new UserRepository($this->conn))->guestPlaceholderId();
        self::assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM notifications WHERE user_id = :u', ['u' => $guestId]));
    }

    // -------------------------------------------------------------------------
    // Agent API / MCP: author_type, never the hash (§1, Testmatrix 8)
    // -------------------------------------------------------------------------

    public function test_api_list_detail_and_queue_carry_author_type_but_never_the_hash(): void
    {
        $boardId = $this->guestBoard('api-guest', ['premoderation' => 'off']);
        $app     = $this->guestApp();
        $guestIdea = (int) $this->json($this->submitAsGuest($app, '/api-guest/ideas', 'Guest idea via form'))['id'];
        $member    = $this->insertUser('member@example.com');
        $memberIdea = $this->seedIdea($boardId, $member, 'Member idea');
        $this->conn->update('boards', ['premoderation' => 'anonymous'], ['id' => $boardId]);
        $pendingGuest = (int) $this->json($this->submitAsGuest($app, '/api-guest/ideas', 'Pending guest idea', ip: '198.51.100.30'))['id'];
        $owner = $this->owner();
        $pair  = (new \Votepit\Security\TokenVault())->generate();
        $this->insertApiToken($this->defaultAccountId(), $boardId, $owner, $pair['hash'], 'Agent', scope: 'moderate');
        $bearer = static fn (string $path): \Psr\Http\Message\ServerRequestInterface => (new \Slim\Psr7\Factory\ServerRequestFactory())
            ->createServerRequest('GET', $path)
            ->withHeader('Authorization', 'Bearer ' . $pair['token']);

        $list = $this->json($app->handle($bearer('/api/v1/ideas')));
        $types = array_column($list['ideas'], 'author_type', 'title');
        ksort($types);
        self::assertSame(['Guest idea via form' => 'guest', 'Member idea' => 'member'], $types);
        $detail = $this->json($app->handle($bearer('/api/v1/ideas/' . $guestIdea)));
        self::assertSame('guest', $detail['idea']['author_type']);
        self::assertSame('member', $this->json($app->handle($bearer('/api/v1/ideas/' . $memberIdea)))['idea']['author_type']);
        $queue = $this->json($app->handle($bearer('/api/v1/ideas?moderation_state=pending')));
        self::assertSame([$pendingGuest => 'guest'], array_column($queue['ideas'], 'author_type', 'id'));

        $everything = json_encode([$list, $detail, $queue]);
        self::assertIsString($everything);
        self::assertStringNotContainsString('anon_author_token_hash', $everything);
        $hash = (string) $this->conn->fetchOne('SELECT anon_author_token_hash FROM ideas WHERE id = :id', ['id' => $guestIdea]);
        self::assertStringNotContainsString($hash, $everything);
    }

    public function test_guest_placeholder_is_not_counted_as_a_signup(): void
    {
        $users  = new UserRepository($this->conn);
        $before = $users->countCreatedSince(new \DateTimeImmutable('-7 days'));
        $users->findOrCreateGuestPlaceholder();

        self::assertSame($before, $users->countCreatedSince(new \DateTimeImmutable('-7 days')));
    }

    // -------------------------------------------------------------------------
    // Retention (§10)
    // -------------------------------------------------------------------------

    public function test_hashes_older_than_180_days_are_nulled(): void
    {
        $boardId = $this->guestBoard('r-old');
        $guestId = (new UserRepository($this->conn))->findOrCreateGuestPlaceholder();
        $old     = $this->seedIdea($boardId, $guestId, 'Old', ['anon_author_token_hash' => str_repeat('a', 64), 'created_at' => (new \DateTimeImmutable('-181 days'))->format('Y-m-d H:i:s')]);
        $fresh   = $this->seedIdea($boardId, $guestId, 'Fresh', ['anon_author_token_hash' => str_repeat('b', 64)]);

        $updated = (new \Votepit\Persistence\IdeaRepository($this->conn))->clearAnonAuthorHashesCreatedBefore(new \DateTimeImmutable('-180 days'));

        self::assertSame(1, $updated);
        self::assertNull($this->conn->fetchOne('SELECT anon_author_token_hash FROM ideas WHERE id = :id', ['id' => $old]));
        self::assertSame(str_repeat('b', 64), $this->conn->fetchOne('SELECT anon_author_token_hash FROM ideas WHERE id = :id', ['id' => $fresh]));
    }

    // -------------------------------------------------------------------------
    // helpers
    // -------------------------------------------------------------------------

    private function owner(): int
    {
        static $n = 0;
        $n++;
        $id = $this->insertUser('owner' . $n . '-' . bin2hex(random_bytes(3)) . '@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $id, 'owner');

        return $id;
    }

    private function ideaIdByTitle(string $title): int
    {
        return (int) $this->conn->fetchOne('SELECT id FROM ideas WHERE title = :t', ['t' => $title]);
    }
}
