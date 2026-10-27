<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ResponseInterface;
use Votepit\Security\AnonymousAuthorHasher;
use Votepit\Security\AnonymousVoterHasher;
use Votepit\Tests\Support\GuestIdeaTestCase;

/**
 * Guest idea submission on POST /{board}/ideas (broaden-use-cases issue 06,
 * ADR 0006) — Testmatrix points 1–5, 7 and 9 for the submit path. The
 * management routes (my ideas / withdraw / bulk reject / settings) and the
 * placeholder special cases live in GuestIdeaManagementTest.
 */
final class AnonymousIdeaSubmissionTest extends GuestIdeaTestCase
{
    // -------------------------------------------------------------------------
    // 1. Defaults: switch off → today's 401, byte-identical
    // -------------------------------------------------------------------------

    /** The baseline: what an anonymous POST returned before issue 06 (AuthZMiddleware::user). */
    private function assertBaseline401(ResponseInterface $response): void
    {
        self::assertSame(401, $response->getStatusCode());
        self::assertSame('Access denied.', (string) $response->getBody());
        self::assertSame([], $response->getHeader('Content-Type'));
        self::assertSame([], $response->getHeader('Set-Cookie'));
    }

    public function test_switch_off_anonymous_post_is_the_unchanged_401_and_logs_the_denial(): void
    {
        $this->insertBoard('g-off');

        $response = $this->submitAsGuest($this->guestApp(), '/g-off/ideas');

        $this->assertBaseline401($response);
        self::assertSame(0, $this->ideaCount());
        // Same AuthzDenialLogger entry and sampling bucket as before.
        $log = $this->readAuditLog();
        self::assertStringContainsString('authz.denied', $log);
        self::assertStringContainsString('"level":"user"', $log);
        self::assertSame(1, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM rate_limits WHERE bucket = :b', ['b' => 'authz.denied:anon:/g-off/ideas']));
        self::assertStringNotContainsString('idea.anon_rejected', $log);
    }

    public function test_switch_off_is_401_even_without_turnstile_or_with_garbage(): void
    {
        $this->insertBoard('g-off-plain');

        $response = $this->guestApp($this->guestConfig(turnstile: false))
            ->handle($this->request('POST', '/g-off-plain/ideas', ['title' => 'x']));

        $this->assertBaseline401($response);
    }

    public function test_signed_in_submit_is_unchanged_on_a_guest_enabled_board(): void
    {
        $this->guestBoard('g-member');
        $memberId = $this->insertUser('g-member@example.com');

        $response = $this->guestApp()->handle($this->request('POST', '/g-member/ideas', [
            'title'    => 'Member idea',
            'body'     => 'Members publish immediately on anonymous mode.',
            '_form_at' => $this->validTimeTrap(),
        ], userId: $memberId));

        self::assertSame(201, $response->getStatusCode());
        $data = $this->json($response);
        self::assertSame(['ok', 'id'], array_keys($data), 'Member response shape is unchanged.');
        self::assertSame([], $this->setCookies($response) === [] ? [] : array_intersect(array_keys($this->setCookies($response)), ['vp_anon_author']));
        $row = $this->conn->fetchAssociative('SELECT author_id, anon_author_token_hash, moderation_state FROM ideas');
        self::assertIsArray($row);
        self::assertSame($memberId, (int) $row['author_id']);
        self::assertNull($row['anon_author_token_hash']);
        self::assertSame('published', $row['moderation_state']);
    }

    public function test_signed_in_submit_keeps_the_idea_submit_bucket_keyed_by_user(): void
    {
        $this->insertBoard('g-member-limit');
        $memberId = $this->insertUser('g-member-limit@example.com');
        $app      = $this->guestApp($this->guestConfig(['rate_limits' => ['idea:submit' => ['limit' => 1, 'window' => 3600]]]));

        $body = ['title' => 'Member idea one', 'body' => 'A description long enough.', '_form_at' => $this->validTimeTrap()];
        self::assertSame(201, $app->handle($this->request('POST', '/g-member-limit/ideas', $body, userId: $memberId))->getStatusCode());
        self::assertSame(429, $app->handle($this->request('POST', '/g-member-limit/ideas', ['title' => 'Member idea two'] + $body, userId: $memberId))->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // Happy path, cookie, audit, privacy (9)
    // -------------------------------------------------------------------------

    public function test_guest_idea_is_stored_pending_under_the_guest_placeholder_with_an_account_bound_hash(): void
    {
        $boardId  = $this->guestBoard('g-happy');
        $response = $this->submitAsGuest($this->guestApp(), '/g-happy/ideas');

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $data = $this->json($response);
        self::assertTrue($data['ok']);
        self::assertSame('pending', $data['moderation_state']);

        $cookies = $this->setCookies($response);
        self::assertArrayHasKey('vp_anon_author', $cookies);
        $raw = $cookies['vp_anon_author'];

        $row = $this->conn->fetchAssociative('SELECT i.*, u.is_guest_placeholder, u.email_hmac FROM ideas i JOIN users u ON u.id = i.author_id WHERE i.id = :id', ['id' => $data['id']]);
        self::assertIsArray($row);
        self::assertSame($boardId, (int) $row['board_id']);
        self::assertSame(1, (int) $row['is_guest_placeholder']);
        self::assertSame('placeholder:guest-author', $row['email_hmac']);
        self::assertSame('pending', $row['moderation_state']);
        self::assertSame(
            (new AnonymousAuthorHasher(self::identityServerKey()))->hashToken($this->defaultAccountId(), $raw),
            $row['anon_author_token_hash'],
        );
    }

    public function test_cookie_attributes_and_no_raw_token_anywhere_at_rest(): void
    {
        $this->guestBoard('g-privacy');
        $this->secureCsrf = true;
        $app      = $this->guestApp($this->guestConfig(['env' => 'prod']));
        $response = $this->submitAsGuest($app, '/g-privacy/ideas');

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $header = '';
        foreach ($response->getHeader('Set-Cookie') as $h) {
            if (str_starts_with($h, 'vp_anon_author=')) {
                $header = $h;
            }
        }
        self::assertStringContainsString('HttpOnly', $header);
        self::assertStringContainsString('SameSite=Lax', $header);
        self::assertStringContainsString('Secure', $header);
        self::assertStringContainsString('Max-Age=15552000', $header);

        $raw = $this->setCookies($response)['vp_anon_author'];
        self::assertStringNotContainsString($raw, $this->readAuditLog());
        self::assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM rate_limits WHERE bucket LIKE :raw', ['raw' => '%' . $raw . '%']));
        self::assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM ideas WHERE anon_author_token_hash = :raw', ['raw' => $raw]));
        // No e-mail or other PII on the placeholder.
        $user = $this->conn->fetchAssociative('SELECT notification_email FROM users WHERE is_guest_placeholder = 1');
        self::assertIsArray($user);
        self::assertNull($user['notification_email']);
    }

    public function test_audit_records_origin_state_and_hmac_never_the_raw_token(): void
    {
        $this->guestBoard('g-audit');
        $response = $this->submitAsGuest($this->guestApp(), '/g-audit/ideas');
        $raw      = $this->setCookies($response)['vp_anon_author'];
        $hash     = (new AnonymousAuthorHasher(self::identityServerKey()))->hashToken($this->defaultAccountId(), $raw);

        $log = $this->readAuditLog();
        self::assertStringContainsString('idea.created', $log);
        self::assertStringContainsString('"origin":"guest"', $log);
        self::assertStringContainsString('"moderation_state":"pending"', $log);
        self::assertStringContainsString('"author_token_hmac":"' . $hash . '"', $log);
    }

    public function test_existing_cookie_is_reused_and_not_reissued(): void
    {
        $this->guestBoard('g-reuse');
        $app   = $this->guestApp();
        $first = $this->submitAsGuest($app, '/g-reuse/ideas', 'First guest idea');
        $raw   = $this->setCookies($first)['vp_anon_author'];

        $second = $this->submitAsGuest($app, '/g-reuse/ideas', 'Second guest idea', cookies: ['vp_anon_author' => $raw]);

        self::assertSame(201, $second->getStatusCode());
        self::assertArrayNotHasKey('vp_anon_author', $this->setCookies($second));
        self::assertSame(1, (int) $this->conn->fetchOne('SELECT COUNT(DISTINCT anon_author_token_hash) FROM ideas'));
    }

    public function test_voter_token_is_taken_over_but_hashes_stay_unlinkable(): void
    {
        $this->guestBoard('g-takeover');
        $voterRaw = AnonymousVoterHasher::generateToken();

        $response = $this->submitAsGuest($this->guestApp(), '/g-takeover/ideas', cookies: ['vp_anon_voter' => $voterRaw]);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame($voterRaw, $this->setCookies($response)['vp_anon_author']);
        $authorHash = (string) $this->conn->fetchOne('SELECT anon_author_token_hash FROM ideas');
        self::assertNotSame((new AnonymousVoterHasher(self::identityServerKey()))->hashToken($voterRaw), $authorHash);
        self::assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM voter_identity_links WHERE voter_token_hash = :h', ['h' => $authorHash]));
    }

    public function test_no_cookie_is_issued_on_a_failed_submission(): void
    {
        $this->guestBoard('g-nocookie');

        $response = $this->submitAsGuest($this->guestApp(), '/g-nocookie/ideas', overrides: ['turnstile_token' => 'wrong']);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame([], $this->setCookies($response));
    }

    // -------------------------------------------------------------------------
    // 2. Turnstile fail-closed
    // -------------------------------------------------------------------------

    public function test_turnstile_not_configured_is_captcha_unavailable_and_stores_nothing(): void
    {
        $this->guestBoard('g-ts-missing');

        $response = $this->submitAsGuest($this->guestApp($this->guestConfig(turnstile: false)), '/g-ts-missing/ideas');

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('captcha_unavailable', $this->errorKey($response));
        self::assertSame(0, $this->ideaCount());
        self::assertStringContainsString('idea.anon_rejected', $this->readAuditLog());
        self::assertStringContainsString('"reason":"captcha_unavailable"', $this->readAuditLog());
    }

    public function test_transport_failure_fails_closed(): void
    {
        $this->guestBoard('g-ts-down');
        $this->turnstile->stubTransportFailure('down-token');

        $response = $this->submitAsGuest($this->guestApp(), '/g-ts-down/ideas', overrides: ['turnstile_token' => 'down-token']);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('captcha_failed', $this->errorKey($response));
        self::assertSame(0, $this->ideaCount());
    }

    /** @return array<string, array{string}> */
    public static function badTokens(): array
    {
        return [
            'empty'        => [''],
            'unsolved'     => ['never-solved'],
            'wrong action' => ['solved-for-login'],
            'no action'    => ['solved-without-action'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badTokens')]
    public function test_bad_turnstile_tokens_are_captcha_failed(string $token): void
    {
        $this->guestBoard('g-ts-bad');
        $this->turnstile->stubSuccess('solved-for-login', 'login');
        $this->turnstile->stubSuccess('solved-without-action');

        $response = $this->submitAsGuest($this->guestApp(), '/g-ts-bad/ideas', overrides: ['turnstile_token' => $token]);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('captcha_failed', $this->errorKey($response));
        self::assertSame(0, $this->ideaCount());
    }

    // -------------------------------------------------------------------------
    // 3. Limits
    // -------------------------------------------------------------------------

    public function test_ip_bucket_groups_ipv6_by_64_and_records_suspicion(): void
    {
        $this->guestBoard('g-ip6');
        $app = $this->guestApp($this->guestConfig(['rate_limits' => ['idea:submit:anon:ip' => ['limit' => 1, 'window' => 3600]]]));

        self::assertSame(201, $this->submitAsGuest($app, '/g-ip6/ideas', 'Idea one', ip: '2001:db8:1:2::1')->getStatusCode());

        $limited = $this->submitAsGuest($app, '/g-ip6/ideas', 'Idea two', ip: '2001:db8:1:2:ffff::9');
        self::assertSame(429, $limited->getStatusCode());
        self::assertSame('rate_limited', $this->errorKey($limited));
        self::assertNotSame('', $limited->getHeaderLine('Retry-After'));
        self::assertSame(1, (int) $this->conn->fetchOne("SELECT COUNT(*) FROM rate_limits WHERE bucket LIKE 'turnstile:suspicion:ip:%'"));

        self::assertSame(201, $this->submitAsGuest($app, '/g-ip6/ideas', 'Idea three', ip: '2001:db8:1:3::1')->getStatusCode());
        self::assertSame(2, $this->ideaCount());
    }

    public function test_ip_bucket_default_is_ten_per_hour(): void
    {
        $this->guestBoard('g-ip-default');
        $app = $this->guestApp();

        for ($i = 1; $i <= 10; $i++) {
            // A fresh device each time, so only the IP bucket can trip.
            $response = $this->submitAsGuest($app, '/g-ip-default/ideas', 'Guest idea number ' . $i);
            self::assertSame(201, $response->getStatusCode(), 'Submission ' . $i . ': ' . $response->getBody());
        }
        self::assertSame(429, $this->submitAsGuest($app, '/g-ip-default/ideas', 'Guest idea eleven')->getStatusCode());
    }

    public function test_token_bucket_limits_one_device_to_three_per_hour(): void
    {
        $this->guestBoard('g-token');
        $app   = $this->guestApp();
        $first = $this->submitAsGuest($app, '/g-token/ideas', 'Device idea one');
        $raw   = $this->setCookies($first)['vp_anon_author'];

        self::assertSame(201, $this->submitAsGuest($app, '/g-token/ideas', 'Device idea two', cookies: ['vp_anon_author' => $raw], ip: '198.51.100.2')->getStatusCode());
        self::assertSame(201, $this->submitAsGuest($app, '/g-token/ideas', 'Device idea three', cookies: ['vp_anon_author' => $raw], ip: '198.51.100.3')->getStatusCode());
        $limited = $this->submitAsGuest($app, '/g-token/ideas', 'Device idea four', cookies: ['vp_anon_author' => $raw], ip: '198.51.100.4');

        self::assertSame(429, $limited->getStatusCode());
        self::assertSame('rate_limited', $this->errorKey($limited));
        self::assertSame(3, $this->ideaCount());
    }

    public function test_board_bucket_caps_volume_across_ips(): void
    {
        $this->guestBoard('g-board-cap', ['premoderation' => 'off']);
        $app = $this->guestApp($this->guestConfig(['rate_limits' => ['idea:submit:anon:board' => ['limit' => 2, 'window' => 3600]]]));

        self::assertSame(201, $this->submitAsGuest($app, '/g-board-cap/ideas', 'Rotating idea one', ip: '198.51.100.11')->getStatusCode());
        self::assertSame(201, $this->submitAsGuest($app, '/g-board-cap/ideas', 'Rotating idea two', ip: '198.51.100.12')->getStatusCode());
        self::assertSame(429, $this->submitAsGuest($app, '/g-board-cap/ideas', 'Rotating idea three', ip: '198.51.100.13')->getStatusCode());
    }

    public function test_unresolvable_client_ip_is_rejected(): void
    {
        $this->guestBoard('g-no-ip');

        $response = $this->submitAsGuest($this->guestApp(), '/g-no-ip/ideas', ip: null);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('rejected', $this->errorKey($response));
        self::assertSame(0, $this->ideaCount());
    }

    public function test_limits_run_before_turnstile_so_floods_cause_no_siteverify_calls(): void
    {
        $this->guestBoard('g-order');
        $app = $this->guestApp($this->guestConfig(['rate_limits' => ['idea:submit:anon:ip' => ['limit' => 1, 'window' => 3600]]]));
        $this->submitAsGuest($app, '/g-order/ideas', 'First idea');

        // A broken siteverify would surface as captcha_failed — the limit must win first.
        $this->turnstile->stubTransportFailure(self::GOOD_TOKEN);
        $response = $this->submitAsGuest($app, '/g-order/ideas', 'Second idea');

        self::assertSame('rate_limited', $this->errorKey($response));
    }

    public function test_queue_cap_of_fifty_pending_guest_ideas_but_members_are_never_blocked(): void
    {
        $boardId = $this->guestBoard('g-queue', ['premoderation' => 'all']);
        $app     = $this->guestApp();
        $first   = $this->submitAsGuest($app, '/g-queue/ideas', 'Guest idea zero');
        self::assertSame(201, $first->getStatusCode());
        $guestId = (int) $this->conn->fetchOne('SELECT id FROM users WHERE is_guest_placeholder = 1');
        for ($i = 1; $i < 50; $i++) {
            $this->seedIdea($boardId, $guestId, 'Seeded guest idea ' . $i, ['moderation_state' => 'pending', 'anon_author_token_hash' => str_repeat('c', 64)]);
        }

        $full = $this->submitAsGuest($app, '/g-queue/ideas', 'One too many', ip: '198.51.100.50');
        self::assertSame(429, $full->getStatusCode());
        self::assertSame('queue_full', $this->errorKey($full));
        self::assertStringContainsString('"reason":"queue_full"', $this->readAuditLog());

        $memberId = $this->insertUser('g-queue-member@example.com');
        $member   = $app->handle($this->request('POST', '/g-queue/ideas', [
            'title' => 'Member idea', 'body' => 'Members are never hit by the guest cap.', '_form_at' => $this->validTimeTrap(),
        ], userId: $memberId));
        self::assertSame(201, $member->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // 4. Guards
    // -------------------------------------------------------------------------

    public function test_honeypot_and_time_trap_reject_silently(): void
    {
        $this->guestBoard('g-bots');
        $app = $this->guestApp();

        $honeypot = $this->submitAsGuest($app, '/g-bots/ideas', overrides: ['website' => 'http://spam']);
        self::assertSame(422, $honeypot->getStatusCode());
        self::assertSame('rejected', $this->errorKey($honeypot));

        $tooFast = $this->submitAsGuest($app, '/g-bots/ideas', overrides: ['_form_at' => 'garbage']);
        self::assertSame(422, $tooFast->getStatusCode());
        self::assertSame('rejected', $this->errorKey($tooFast));
        self::assertSame(0, $this->ideaCount());
    }

    public function test_lengths_are_validated_like_for_members(): void
    {
        $this->guestBoard('g-len');

        $response = $this->submitAsGuest($this->guestApp(), '/g-len/ideas', 'ab', ['body' => 'short']);

        self::assertSame(422, $response->getStatusCode());
        $error = $this->json($response)['error'];
        self::assertSame('validation_error', $error['key']);
        self::assertArrayHasKey('title', $error['fields']);
        self::assertArrayHasKey('body', $error['fields']);
    }

    /** @return array<string, array{string, string}> */
    public static function urlSubmissions(): array
    {
        return [
            'https in title'   => ['Visit HTTPS://example.com now', 'A perfectly ordinary description.'],
            'hxxp in body'     => ['An ordinary title', 'see hxxp://evil.test for more'],
            'www with space'   => ['An ordinary title', 'go to www .example.com please'],
            'full-width'       => ['ｈｔｔｐｓ://example.com', 'A perfectly ordinary description.'],
            'zero-width'       => ['An ordinary title', "ht\u{200B}tps://example.com is great"],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('urlSubmissions')]
    public function test_urls_are_rejected_for_guests(string $title, string $body): void
    {
        $this->guestBoard('g-url');

        $response = $this->submitAsGuest($this->guestApp(), '/g-url/ideas', $title, ['body' => $body]);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('url_not_allowed', $this->errorKey($response));
        self::assertSame(0, $this->ideaCount());
    }

    public function test_bare_domains_are_allowed_for_guests(): void
    {
        $this->guestBoard('g-domain');

        $response = $this->submitAsGuest($this->guestApp(), '/g-domain/ideas', 'Support Node.js on example.com', ['body' => 'z.B. like example.com does it.']);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
    }

    public function test_word_filter_is_forced_for_guests_even_if_the_board_disabled_it(): void
    {
        $boardId = $this->guestBoard('g-words');
        $this->conn->update('boards', ['moderation_enabled' => 0], ['id' => $boardId]);
        $this->conn->insert('board_blocklist', ['board_id' => $boardId, 'word' => 'forbiddenword', 'created_at' => '2026-01-01 00:00:00']);

        $response = $this->submitAsGuest($this->guestApp(), '/g-words/ideas', 'An idea with forbiddenword inside');

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('moderation_blocked', $this->errorKey($response));
        self::assertStringContainsString('"reason":"moderation_blocked"', $this->readAuditLog());
        self::assertStringNotContainsString('forbiddenword', $this->readAuditLog());
        self::assertSame(0, $this->ideaCount());
    }

    // -------------------------------------------------------------------------
    // 5. Pre-moderation modes
    // -------------------------------------------------------------------------

    public function test_anonymous_mode_holds_guest_ideas_back_and_publishes_members(): void
    {
        $boardId = $this->guestBoard('g-pm');
        $ownerId = $this->insertUser('g-pm-owner@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');
        $app = $this->guestApp();
        $this->configureWebhook($app, 'g-pm', $ownerId);

        $guest = $this->json($this->submitAsGuest($app, '/g-pm/ideas', 'Hidden guest idea'));

        // Pending: absent from the public list, the detail and the stats.
        $home = $this->json($app->handle($this->request('GET', '/g-pm')));
        self::assertSame([], array_column($home['ideas'], 'title'));
        self::assertSame(0, $home['stats']['total_ideas']);
        self::assertSame(404, $app->handle($this->request('GET', '/g-pm/ideas/' . $guest['id']))->getStatusCode());
        self::assertSame([], $this->webhookEvents(), 'A pending guest idea fires no idea.created webhook.');
        // Moderators get the existing aggregated in-app notice.
        self::assertSame(1, (int) $this->conn->fetchOne("SELECT COUNT(*) FROM notifications WHERE type = 'ideas_pending_approval' AND user_id = :u", ['u' => $ownerId]));

        // Approval publishes it and fires the webhook (existing issue 05 logic).
        $approve = $app->handle($this->request('POST', '/admin/boards/g-pm/queue/approve', ['ids' => [$guest['id']]], userId: $ownerId));
        self::assertSame([$guest['id']], $this->json($approve)['approved']);
        self::assertSame(['idea.created'], $this->webhookEvents());
        self::assertSame($boardId, (int) $this->conn->fetchOne('SELECT board_id FROM ideas WHERE id = :id', ['id' => $guest['id']]));
    }

    public function test_off_mode_publishes_guest_ideas_immediately_with_webhook(): void
    {
        $this->guestBoard('g-pm-off', ['premoderation' => 'off']);
        $ownerId = $this->insertUser('g-pm-off-owner@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $ownerId, 'owner');
        $app = $this->guestApp();
        $this->configureWebhook($app, 'g-pm-off', $ownerId);

        $response = $this->submitAsGuest($app, '/g-pm-off/ideas', 'Public guest idea');

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('published', $this->json($response)['moderation_state']);
        self::assertSame(['Public guest idea'], array_column($this->json($app->handle($this->request('GET', '/g-pm-off')))['ideas'], 'title'));
        self::assertSame(['idea.created'], $this->webhookEvents());
    }

    // -------------------------------------------------------------------------
    // Board resolution, phases, plan gate
    // -------------------------------------------------------------------------

    public function test_private_and_unknown_boards_are_404(): void
    {
        $this->guestBoard('g-private', ['visibility' => 'private']);
        $app = $this->guestApp();

        self::assertSame(404, $this->submitAsGuest($app, '/g-private/ideas')->getStatusCode());
        self::assertSame(404, $this->submitAsGuest($app, '/g-nowhere/ideas')->getStatusCode());
        self::assertSame(0, $this->ideaCount());
    }

    public function test_closed_submission_window_applies_to_guests_without_exemption(): void
    {
        $this->guestBoard('g-closed', ['submissions_closes_at' => '2020-01-01 00:00:00']);

        $response = $this->submitAsGuest($this->guestApp(), '/g-closed/ideas');

        self::assertSame(423, $response->getStatusCode());
        self::assertSame('submissions_closed', $this->errorKey($response));
    }

    public function test_free_plan_turns_guest_submissions_into_the_baseline_401(): void
    {
        $this->guestBoard('g-free');
        $this->setAccountPlan($this->defaultAccountId(), 'free');

        $this->assertBaseline401($this->submitAsGuest($this->guestApp(), '/g-free/ideas'));
        self::assertSame(0, $this->ideaCount());
    }

    // -------------------------------------------------------------------------
    // 7. Cross-tenant
    // -------------------------------------------------------------------------

    public function test_board_of_another_account_is_404_and_hashes_differ_per_account(): void
    {
        $accountA = $this->insertAccount(['slug' => 'acct-ga']);
        $accountB = $this->insertAccount(['slug' => 'acct-gb']);
        $this->guestBoard('shared', ['account_id' => $accountA, 'is_default' => 0]);
        $this->guestBoard('only-b', ['account_id' => $accountB, 'is_default' => 0]);
        $this->guestBoard('shared', ['account_id' => $accountB, 'is_default' => 0]);
        $app = $this->guestApp($this->guestConfig(['routing_mode' => 'cloud']));

        // B's board is not reachable under A's prefix.
        self::assertSame(404, $this->submitAsGuest($app, '/acct-ga/only-b/ideas')->getStatusCode());

        $inA = $this->submitAsGuest($app, '/acct-ga/shared/ideas', 'Idea in A');
        self::assertSame(201, $inA->getStatusCode(), (string) $inA->getBody());
        $raw = $this->setCookies($inA)['vp_anon_author'];
        $inB = $this->submitAsGuest($app, '/acct-gb/shared/ideas', 'Idea in B', cookies: ['vp_anon_author' => $raw], ip: '198.51.100.77');
        self::assertSame(201, $inB->getStatusCode());

        $hashes = $this->conn->fetchFirstColumn('SELECT anon_author_token_hash FROM ideas ORDER BY id');
        self::assertCount(2, $hashes);
        self::assertNotSame($hashes[0], $hashes[1], 'The same cookie must hash differently per account.');
    }

    // -------------------------------------------------------------------------
    // helpers
    // -------------------------------------------------------------------------

    /** @param \Slim\App<null> $app */
    private function configureWebhook(\Slim\App $app, string $slug, int $ownerId): void
    {
        $response = $app->handle($this->request('PUT', '/admin/boards/' . $slug . '/webhook', ['url' => 'https://93.184.216.34/hook'], userId: $ownerId));
        self::assertSame(200, $response->getStatusCode());
    }

}
