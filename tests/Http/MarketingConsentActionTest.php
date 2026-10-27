<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Config;
use Votepit\Mail\InMemoryMailer;
use Votepit\Security\CsrfService;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Integration tests for the product-news consent (migration 0112, W1):
 *   signup checkbox on POST /login (double opt-in on the magic link),
 *   profile toggle + confirm link under /account/marketing-consent,
 *   self-host stays unchanged (everything answers 404 / ignores the flag).
 */
final class MarketingConsentActionTest extends IntegrationTestCase
{
    private const PRODUCT_NEWS_SENTENCE = 'Clicking this link also confirms your subscription to Votepit product news';

    private function csrf(): CsrfService
    {
        return new CsrfService(str_repeat('a', 64), 3600, false);
    }

    /** Hosted installation with the product-news consent enabled. */
    private function marketingConfig(): Config
    {
        return Config::fromArray($this->testConfigArray(['routing_mode' => 'cloud', 'marketing_consent' => true]));
    }

    private function authedRequest(string $method, string $uri, int $userId): ServerRequestInterface
    {
        $csrf   = $this->csrf();
        $token  = $csrf->generate();
        $signed = $csrf->sign($token);

        return (new ServerRequestFactory())
            ->createServerRequest($method, $uri)
            ->withCookieParams([
                $csrf->cookieName() => $signed,
                'votepit_sess'      => $this->sessionCookie($userId),
            ])
            ->withHeader('X-CSRF-Token', $token);
    }

    /**
     * POST /login as the SPA sends it. $productNews null = field not sent at all.
     *
     * @param App<null> $app
     */
    private function postLogin(App $app, string $email, ?string $returnTo, ?bool $productNews): ResponseInterface
    {
        $csrf  = $this->csrf();
        $token = $csrf->generate();

        $body = ['email' => $email, '_csrf' => $token];
        if ($returnTo !== null) {
            $body['r'] = $returnTo;
        }
        if ($productNews !== null) {
            $body['product_news'] = $productNews;
        }

        return $app->handle(
            (new ServerRequestFactory())->createServerRequest('POST', '/login')
                ->withCookieParams([$csrf->cookieName() => $csrf->sign($token)])
                ->withParsedBody($body),
        );
    }

    /** Plain token of the most recent mail (hex, see TokenVault). */
    private function tokenFromMail(InMemoryMailer $mailer, string $path): string
    {
        $sent = $mailer->lastSent();
        self::assertIsArray($sent);
        self::assertSame(1, preg_match('/' . preg_quote($path, '/') . '\?token=([a-f0-9]+)/', $sent['body'], $m), $sent['body']);

        return $m[1];
    }

    /** @param App<null> $app */
    private function verifyLoginLink(App $app, string $token): ResponseInterface
    {
        return $app->handle(
            (new ServerRequestFactory())->createServerRequest('GET', '/login/verify')
                ->withQueryParams(['token' => $token, 'r' => '/signup/account']),
        );
    }

    /** @return array<string, mixed>|null */
    private function consentRow(int $userId): ?array
    {
        $row = $this->conn->fetchAssociative('SELECT * FROM marketing_consents WHERE user_id = :id', ['id' => $userId]);

        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed> */
    private function requireConsent(int $userId): array
    {
        $row = $this->consentRow($userId);
        self::assertIsArray($row);

        return $row;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function fetchRow(string $sql, array $params): array
    {
        $row = $this->conn->fetchAssociative($sql, $params);
        self::assertIsArray($row);

        return $row;
    }

    private function userId(string $email): int
    {
        return (int) $this->conn->fetchOne(
            'SELECT id FROM users WHERE email_hmac = :hmac',
            ['hmac' => (new \Votepit\Security\IdentityHasher(self::identityServerKey()))->hash($email)],
        );
    }

    /** Gives a user a confirmed notification e-mail (the only address a profile opt-in can use). */
    private function confirmNotificationEmail(int $userId, string $address): void
    {
        $this->conn->update('users', ['notification_email' => $address], ['id' => $userId]);
    }

    // -------------------------------------------------------------------------
    // Signup: POST /login on the signup step (checkbox + § 7 Abs. 3 notice)
    // -------------------------------------------------------------------------

    public function test_ticked_signup_checkbox_stores_pending_consent_and_extends_the_login_mail(): void
    {
        $mailer = new InMemoryMailer();
        $app    = $this->createApp($mailer, config: $this->marketingConfig());

        $response = $this->postLogin($app, 'founder@example.com', '/signup/account', true);

        self::assertSame(200, $response->getStatusCode());
        $userId  = $this->userId('founder@example.com');
        $consent = $this->requireConsent($userId);
        self::assertSame('pending', $consent['status']);
        self::assertSame('signup', $consent['source']);
        self::assertSame(\Votepit\Domain\MarketingConsent::TEXT_VERSION, $consent['text_version']);
        self::assertNotNull($consent['requested_at']);
        self::assertNull($consent['confirmed_at']);
        self::assertNull($consent['confirm_token_hash'], 'signup consent rides on the login link, no separate token');

        $sent = $mailer->lastSent();
        self::assertNotNull($sent);
        self::assertSame(1, substr_count($sent['body'], self::PRODUCT_NEWS_SENTENCE), 'exactly one extra sentence');

        $tokenRow = $this->fetchRow('SELECT confirms_product_news FROM login_tokens WHERE user_id = :id', ['id' => $userId]);
        self::assertSame(1, (int) $tokenRow['confirms_product_news']);
    }

    public function test_unticked_signup_keeps_the_login_mail_unchanged(): void
    {
        $mailer = new InMemoryMailer();
        $app    = $this->createApp($mailer, config: $this->marketingConfig());

        $this->postLogin($app, 'plain@example.com', '/signup/account', false);

        $userId = $this->userId('plain@example.com');
        self::assertNull($this->consentRow($userId), 'no consent without the checkbox');

        $user = $this->fetchRow('SELECT existing_customer_notice_at, marketing_email FROM users WHERE id = :id', ['id' => $userId]);
        self::assertNull($user['existing_customer_notice_at'], 'nothing is proven before the link is redeemed');
        self::assertNull($user['marketing_email']);

        $sent = $mailer->lastSent();
        self::assertNotNull($sent);
        self::assertStringNotContainsString('product news', $sent['body']);
        self::assertStringNotContainsString('product news', $sent['subject']);
    }

    public function test_post_alone_records_neither_the_notice_nor_the_address(): void
    {
        $mailer = new InMemoryMailer();
        $app    = $this->createApp($mailer, config: $this->marketingConfig());

        $this->postLogin($app, 'unproven@example.com', '/signup/account', true);

        $userId = $this->userId('unproven@example.com');
        $user   = $this->fetchRow('SELECT existing_customer_notice_at, marketing_email FROM users WHERE id = :id', ['id' => $userId]);
        self::assertNull($user['existing_customer_notice_at']);
        self::assertNull($user['marketing_email']);
        // The address waits on the token row until the link is redeemed.
        $token = $this->fetchRow('SELECT signup_marketing_email FROM login_tokens WHERE user_id = :id', ['id' => $userId]);
        self::assertSame('unproven@example.com', $token['signup_marketing_email']);
    }

    public function test_redeeming_the_signup_link_records_the_notice_and_the_address(): void
    {
        $mailer = new InMemoryMailer();
        $app    = $this->createApp($mailer, config: $this->marketingConfig());
        $this->postLogin($app, 'proven@example.com', '/signup/account', false);
        $userId = $this->userId('proven@example.com');

        $this->verifyLoginLink($app, $this->tokenFromMail($mailer, '/login/verify'));

        $user = $this->fetchRow('SELECT existing_customer_notice_at, marketing_email FROM users WHERE id = :id', ['id' => $userId]);
        self::assertNotNull($user['existing_customer_notice_at']);
        self::assertSame('proven@example.com', $user['marketing_email']);
        $token = $this->fetchRow('SELECT signup_marketing_email FROM login_tokens WHERE user_id = :id', ['id' => $userId]);
        self::assertNull($token['signup_marketing_email'], 'the address leaves the token with the consumed link');
    }

    public function test_an_unredeemed_attacker_post_leaves_the_victim_row_untouched(): void
    {
        $victim = $this->insertUser('victim@example.com');
        $mailer = new InMemoryMailer();
        $app    = $this->createApp($mailer, config: $this->marketingConfig());

        $this->postLogin($app, 'victim@example.com', '/signup/account', false);

        $user = $this->fetchRow('SELECT existing_customer_notice_at, marketing_email FROM users WHERE id = :id', ['id' => $victim]);
        self::assertNull($user['existing_customer_notice_at'], 'no § 7 Abs. 3 proof from an unredeemed request');
        self::assertNull($user['marketing_email'], 'no plaintext address from an unredeemed request');
        self::assertNull($this->consentRow($victim));
    }

    public function test_expired_open_signup_links_lose_their_address_on_the_next_request(): void
    {
        $mailer = new InMemoryMailer();
        $app    = $this->createApp($mailer, config: $this->marketingConfig());
        $this->postLogin($app, 'stale@example.com', '/signup/account', false);
        $staleUser = $this->userId('stale@example.com');
        $this->conn->update('login_tokens', ['expires_at' => (new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s')], ['user_id' => $staleUser]);

        $this->postLogin($app, 'fresh@example.com', '/signup/account', false);

        $token = $this->fetchRow('SELECT signup_marketing_email FROM login_tokens WHERE user_id = :id', ['id' => $staleUser]);
        self::assertNull($token['signup_marketing_email']);
    }

    public function test_pending_signup_consent_of_an_expired_link_is_removed_on_the_next_request(): void
    {
        $mailer = new InMemoryMailer();
        $app    = $this->createApp($mailer, config: $this->marketingConfig());
        $this->postLogin($app, 'stale@example.com', '/signup/account', true);
        $staleUser = $this->userId('stale@example.com');
        $this->conn->update('login_tokens', ['expires_at' => (new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s')], ['user_id' => $staleUser]);

        $this->postLogin($app, 'fresh@example.com', '/signup/account', true);

        self::assertNull($this->consentRow($staleUser), 'an unredeemed, expired signup link leaves no pending consent');
        self::assertSame('pending', $this->requireConsent($this->userId('fresh@example.com'))['status'], 'an open link keeps its pending consent');
    }

    public function test_a_later_request_without_the_checkbox_drops_the_unredeemed_pending_consent(): void
    {
        $mailer = new InMemoryMailer();
        $app    = $this->createApp($mailer, config: $this->marketingConfig());
        $this->postLogin($app, 'victim@example.com', '/signup/account', true);
        $userId = $this->userId('victim@example.com');

        // The new link replaces the ticked one, so nothing can confirm the consent any more.
        $this->postLogin($app, 'victim@example.com', null, null);

        self::assertNull($this->consentRow($userId));
    }

    public function test_login_without_the_signup_return_path_ignores_the_checkbox(): void
    {
        $mailer = new InMemoryMailer();
        $app    = $this->createApp($mailer, config: $this->marketingConfig());

        $this->postLogin($app, 'returning@example.com', null, true);

        $userId = $this->userId('returning@example.com');
        self::assertNull($this->consentRow($userId));
        $sent = $mailer->lastSent();
        self::assertNotNull($sent);
        self::assertStringNotContainsString(self::PRODUCT_NEWS_SENTENCE, $sent['body']);
    }

    public function test_self_host_ignores_the_signup_consent_entirely(): void
    {
        $mailer = new InMemoryMailer();
        $app    = $this->createApp($mailer); // default config: marketing_consent off

        $this->postLogin($app, 'selfhost@example.com', '/signup/account', true);

        $userId = $this->userId('selfhost@example.com');
        self::assertNull($this->consentRow($userId));
        $user = $this->fetchRow('SELECT existing_customer_notice_at, marketing_email FROM users WHERE id = :id', ['id' => $userId]);
        self::assertNull($user['existing_customer_notice_at']);
        self::assertNull($user['marketing_email']);
        $sent = $mailer->lastSent();
        self::assertNotNull($sent);
        self::assertStringNotContainsString(self::PRODUCT_NEWS_SENTENCE, $sent['body']);
    }

    public function test_redeeming_the_signup_link_confirms_the_consent(): void
    {
        $mailer = new InMemoryMailer();
        $app    = $this->createApp($mailer, config: $this->marketingConfig());
        $this->postLogin($app, 'confirm-me@example.com', '/signup/account', true);
        $userId = $this->userId('confirm-me@example.com');

        $response = $this->verifyLoginLink($app, $this->tokenFromMail($mailer, '/login/verify'));

        self::assertSame(200, $response->getStatusCode());
        $consent = $this->requireConsent($userId);
        self::assertSame('confirmed', $consent['status']);
        self::assertNotNull($consent['confirmed_at']);
    }

    public function test_replayed_signup_link_does_not_undo_a_withdrawal(): void
    {
        $mailer = new InMemoryMailer();
        $app    = $this->createApp($mailer, config: $this->marketingConfig());
        $this->postLogin($app, 'replay@example.com', '/signup/account', true);
        $userId = $this->userId('replay@example.com');
        $token  = $this->tokenFromMail($mailer, '/login/verify');
        $this->verifyLoginLink($app, $token);

        $app->handle($this->authedRequest('PUT', '/account/marketing-consent', $userId)->withParsedBody(['enabled' => false]));

        // A second click on the same (already used) link lands in the grace window and must stay a no-op.
        $this->verifyLoginLink($app, $token);

        self::assertSame('withdrawn', $this->requireConsent($userId)['status']);
    }

    public function test_signup_after_an_objection_does_not_restore_the_address(): void
    {
        $mailer = new InMemoryMailer();
        $app    = $this->createApp($mailer, config: $this->marketingConfig());
        $this->postLogin($app, 'objector@example.com', '/signup/account', false);
        $this->verifyLoginLink($app, $this->tokenFromMail($mailer, '/login/verify'));
        $userId = $this->userId('objector@example.com');
        $app->handle($this->authedRequest('PUT', '/account/marketing-consent', $userId)->withParsedBody(['enabled' => false]));

        $this->postLogin($app, 'objector@example.com', '/signup/account', false);
        $this->verifyLoginLink($app, $this->tokenFromMail($mailer, '/login/verify'));

        $user = $this->fetchRow('SELECT marketing_email FROM users WHERE id = :id', ['id' => $userId]);
        self::assertNull($user['marketing_email'], 'an objection stays sticky');
    }

    // -------------------------------------------------------------------------
    // Profile: GET / PUT /account/marketing-consent and the confirm link
    // -------------------------------------------------------------------------

    public function test_every_endpoint_answers_404_when_marketing_is_disabled(): void
    {
        $userId = $this->insertUser('profile-off@example.com');
        $app    = $this->createApp(); // self-host default

        $get     = $app->handle($this->authedRequest('GET', '/account/marketing-consent', $userId));
        $put     = $app->handle($this->authedRequest('PUT', '/account/marketing-consent', $userId)->withParsedBody(['enabled' => true]));
        $confirm = $app->handle($this->authedRequest('GET', '/account/marketing-consent/confirm', $userId)->withQueryParams(['token' => 'abc']));

        self::assertSame(404, $get->getStatusCode());
        self::assertSame(404, $put->getStatusCode());
        self::assertSame(404, $confirm->getStatusCode());
        self::assertNull($this->consentRow($userId));
    }

    public function test_opt_in_requires_a_confirmed_notification_email(): void
    {
        $userId = $this->insertUser('no-address@example.com');
        $app    = $this->createApp(config: $this->marketingConfig());

        $response = $app->handle($this->authedRequest('PUT', '/account/marketing-consent', $userId)->withParsedBody(['enabled' => true]));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('notification_email_required', json_decode((string) $response->getBody(), true)['error']['key']);
        self::assertNull($this->consentRow($userId));
    }

    public function test_opt_in_sends_a_confirmation_mail_and_stores_only_the_token_hash(): void
    {
        $userId = $this->insertUser('opt-in@example.com');
        $this->confirmNotificationEmail($userId, 'inbox@example.com');
        $mailer = new InMemoryMailer();
        $app    = $this->createApp($mailer, config: $this->marketingConfig());

        $response = $app->handle($this->authedRequest('PUT', '/account/marketing-consent', $userId)->withParsedBody(['enabled' => true]));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('pending', json_decode((string) $response->getBody(), true)['status']);

        $consent = $this->requireConsent($userId);
        self::assertSame('pending', $consent['status']);
        self::assertSame('profile', $consent['source']);
        self::assertNotNull($consent['confirm_token_hash']);
        self::assertNotNull($consent['confirm_expires_at']);

        $sent = $mailer->lastSent();
        self::assertNotNull($sent);
        self::assertSame('inbox@example.com', $sent['to']);
        self::assertSame('Confirm your Votepit product news', $sent['subject']);
        $plain = $this->tokenFromMail($mailer, '/account/marketing-consent/confirm');
        self::assertNotSame($plain, $consent['confirm_token_hash'], 'only the hash is stored');
        self::assertSame(hash('sha256', $plain), $consent['confirm_token_hash']);
        self::assertNull(
            $this->fetchRow('SELECT marketing_email FROM users WHERE id = :id', ['id' => $userId])['marketing_email'],
            'the request alone sets no delivery address',
        );
    }

    public function test_confirmation_mail_follows_the_stored_language(): void
    {
        $userId = $this->insertUser('german-optin@example.com');
        $this->confirmNotificationEmail($userId, 'inbox-de@example.com');
        $this->conn->update('users', ['digest_language' => 'de'], ['id' => $userId]);
        $mailer = new InMemoryMailer();
        $app    = $this->createApp($mailer, config: $this->marketingConfig());

        $app->handle($this->authedRequest('PUT', '/account/marketing-consent', $userId)->withParsedBody(['enabled' => true]));

        $sent = $mailer->lastSent();
        self::assertNotNull($sent);
        self::assertSame('Bestätige deine Votepit-Produktneuigkeiten', $sent['subject']);  // export-ok: comment-language
        self::assertIsString($sent['html']);
        self::assertStringContainsString('Falls der Button nicht funktioniert, kopiere diesen Link in deinen Browser:', $sent['html']);  // export-ok: comment-language
        self::assertStringNotContainsString('If the button does not work', $sent['html']);
    }

    public function test_confirm_link_confirms_the_consent_and_status_reflects_it(): void
    {
        $userId = $this->insertUser('confirm-profile@example.com');
        $this->confirmNotificationEmail($userId, 'inbox2@example.com');
        $mailer = new InMemoryMailer();
        $app    = $this->createApp($mailer, config: $this->marketingConfig());
        $app->handle($this->authedRequest('PUT', '/account/marketing-consent', $userId)->withParsedBody(['enabled' => true]));
        $token = $this->tokenFromMail($mailer, '/account/marketing-consent/confirm');

        $confirm = $app->handle($this->authedRequest('GET', '/account/marketing-consent/confirm', $userId)->withQueryParams(['token' => $token]));
        self::assertSame(200, $confirm->getStatusCode());

        $status = $app->handle($this->authedRequest('GET', '/account/marketing-consent', $userId));
        self::assertSame('confirmed', json_decode((string) $status->getBody(), true)['status']);
        self::assertNotNull($this->requireConsent($userId)['confirmed_at']);
        self::assertSame(
            'inbox2@example.com',
            $this->fetchRow('SELECT marketing_email FROM users WHERE id = :id', ['id' => $userId])['marketing_email'],
            'the address goes live only on confirmation',
        );
        self::assertNull($this->requireConsent($userId)['confirm_token_hash'], 'the one-time token is spent');

        $replay = $app->handle($this->authedRequest('GET', '/account/marketing-consent/confirm', $userId)->withQueryParams(['token' => $token]));
        self::assertSame(200, $replay->getStatusCode(), 'a second click on a confirmed link is success');
        self::assertSame('confirmed', json_decode((string) $replay->getBody(), true)['status']);
    }

    public function test_double_submit_replaces_the_pending_link(): void
    {
        $userId = $this->insertUser('double@example.com');
        $this->confirmNotificationEmail($userId, 'double-inbox@example.com');
        $mailer = new InMemoryMailer();
        $app    = $this->createApp($mailer, config: $this->marketingConfig());

        $app->handle($this->authedRequest('PUT', '/account/marketing-consent', $userId)->withParsedBody(['enabled' => true]));
        $firstToken = $this->tokenFromMail($mailer, '/account/marketing-consent/confirm');
        $app->handle($this->authedRequest('PUT', '/account/marketing-consent', $userId)->withParsedBody(['enabled' => true]));
        $secondToken = $this->tokenFromMail($mailer, '/account/marketing-consent/confirm');

        self::assertSame(1, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM marketing_consents WHERE user_id = :id', ['id' => $userId]));

        $old = $app->handle($this->authedRequest('GET', '/account/marketing-consent/confirm', $userId)->withQueryParams(['token' => $firstToken]));
        self::assertSame(400, $old->getStatusCode(), 'superseded link no longer works');

        $new = $app->handle($this->authedRequest('GET', '/account/marketing-consent/confirm', $userId)->withQueryParams(['token' => $secondToken]));
        self::assertSame(200, $new->getStatusCode());
    }

    public function test_tampered_confirm_link_is_rejected_and_the_consent_stays_pending(): void
    {
        $userId = $this->insertUser('tamper@example.com');
        $this->confirmNotificationEmail($userId, 'tamper-inbox@example.com');
        $mailer = new InMemoryMailer();
        $app    = $this->createApp($mailer, config: $this->marketingConfig());
        $app->handle($this->authedRequest('PUT', '/account/marketing-consent', $userId)->withParsedBody(['enabled' => true]));
        $token  = $this->tokenFromMail($mailer, '/account/marketing-consent/confirm');
        $forged = substr($token, 0, -1) . ($token[-1] === '0' ? '1' : '0');

        $response = $app->handle($this->authedRequest('GET', '/account/marketing-consent/confirm', $userId)->withQueryParams(['token' => $forged]));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('invalid_token', json_decode((string) $response->getBody(), true)['error']['key']);
        self::assertSame('pending', $this->requireConsent($userId)['status']);
    }

    public function test_expired_confirm_link_is_rejected(): void
    {
        $userId = $this->insertUser('expired@example.com');
        $this->confirmNotificationEmail($userId, 'expired-inbox@example.com');
        $mailer = new InMemoryMailer();
        $app    = $this->createApp($mailer, config: $this->marketingConfig());
        $app->handle($this->authedRequest('PUT', '/account/marketing-consent', $userId)->withParsedBody(['enabled' => true]));
        $token  = $this->tokenFromMail($mailer, '/account/marketing-consent/confirm');
        $this->conn->update('marketing_consents', ['confirm_expires_at' => (new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s')], ['user_id' => $userId]);

        $response = $app->handle($this->authedRequest('GET', '/account/marketing-consent/confirm', $userId)->withQueryParams(['token' => $token]));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('pending', $this->requireConsent($userId)['status']);
    }

    public function test_confirm_link_of_another_account_is_rejected(): void
    {
        $owner = $this->insertUser('owner-link@example.com');
        $other = $this->insertUser('other-session@example.com');
        $this->confirmNotificationEmail($owner, 'owner-inbox@example.com');
        $mailer = new InMemoryMailer();
        $app    = $this->createApp($mailer, config: $this->marketingConfig());
        $app->handle($this->authedRequest('PUT', '/account/marketing-consent', $owner)->withParsedBody(['enabled' => true]));
        $token = $this->tokenFromMail($mailer, '/account/marketing-consent/confirm');

        $response = $app->handle($this->authedRequest('GET', '/account/marketing-consent/confirm', $other)->withQueryParams(['token' => $token]));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('pending', $this->requireConsent($owner)['status']);
        self::assertNull($this->consentRow($other));
    }

    public function test_status_reports_only_the_callers_own_consent(): void
    {
        $owner = $this->insertUser('status-owner@example.com');
        $other = $this->insertUser('status-other@example.com');
        $this->conn->insert('marketing_consents', [
            'user_id'      => $owner,
            'status'       => 'confirmed',
            'source'       => 'profile',
            'text_version' => \Votepit\Domain\MarketingConsent::TEXT_VERSION,
            'confirmed_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
        $app = $this->createApp(config: $this->marketingConfig());

        $ownerStatus = json_decode((string) $app->handle($this->authedRequest('GET', '/account/marketing-consent', $owner))->getBody(), true);
        $otherStatus = json_decode((string) $app->handle($this->authedRequest('GET', '/account/marketing-consent', $other))->getBody(), true);

        self::assertSame('confirmed', $ownerStatus['status']);
        self::assertSame(\Votepit\Domain\MarketingConsent::TEXT_VERSION, $ownerStatus['text_version']);
        self::assertNull($otherStatus['status']);
    }

    public function test_opt_in_when_already_confirmed_sends_nothing(): void
    {
        $userId = $this->insertUser('already@example.com');
        $this->confirmNotificationEmail($userId, 'already-inbox@example.com');
        $this->conn->insert('marketing_consents', [
            'user_id'      => $userId,
            'status'       => 'confirmed',
            'source'       => 'profile',
            'text_version' => \Votepit\Domain\MarketingConsent::TEXT_VERSION,
            'confirmed_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
        $mailer = new InMemoryMailer();
        $app    = $this->createApp($mailer, config: $this->marketingConfig());

        $response = $app->handle($this->authedRequest('PUT', '/account/marketing-consent', $userId)->withParsedBody(['enabled' => true]));

        self::assertSame('confirmed', json_decode((string) $response->getBody(), true)['status']);
        self::assertSame(0, $mailer->count());
    }

    public function test_opt_out_withdraws_immediately_and_clears_the_address(): void
    {
        $userId = $this->insertUser('opt-out@example.com');
        $this->conn->insert('marketing_consents', [
            'user_id'      => $userId,
            'status'       => 'confirmed',
            'source'       => 'signup',
            'text_version' => \Votepit\Domain\MarketingConsent::TEXT_VERSION,
            'confirmed_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
        $this->conn->update('users', ['marketing_email' => 'opt-out@example.com'], ['id' => $userId]);
        $mailer = new InMemoryMailer();
        $app    = $this->createApp($mailer, config: $this->marketingConfig());

        $response = $app->handle($this->authedRequest('PUT', '/account/marketing-consent', $userId)->withParsedBody(['enabled' => false]));

        self::assertSame('withdrawn', json_decode((string) $response->getBody(), true)['status']);
        $consent = $this->requireConsent($userId);
        self::assertSame('withdrawn', $consent['status']);
        self::assertNotNull($consent['withdrawn_at']);
        self::assertNull($this->conn->fetchOne('SELECT marketing_email FROM users WHERE id = :id', ['id' => $userId]));
        self::assertSame(0, $mailer->count(), 'withdrawal sends no mail');
    }

    public function test_opt_out_without_any_earlier_request_records_an_objection(): void
    {
        $userId = $this->insertUser('objection@example.com');
        $app    = $this->createApp(config: $this->marketingConfig());

        $app->handle($this->authedRequest('PUT', '/account/marketing-consent', $userId)->withParsedBody(['enabled' => false]));

        $consent = $this->requireConsent($userId);
        self::assertSame('withdrawn', $consent['status']);
        self::assertNotNull($consent['withdrawn_at']);
    }

    public function test_missing_enabled_flag_is_a_validation_error(): void
    {
        $userId = $this->insertUser('validation@example.com');
        $app    = $this->createApp(config: $this->marketingConfig());

        $response = $app->handle($this->authedRequest('PUT', '/account/marketing-consent', $userId)->withParsedBody(['enabled' => 'yes']));

        self::assertSame(422, $response->getStatusCode());
        self::assertNull($this->consentRow($userId));
    }

    public function test_the_put_requires_an_authenticated_csrf_protected_request(): void
    {
        $app = $this->createApp(config: $this->marketingConfig());

        $response = $app->handle(
            (new ServerRequestFactory())->createServerRequest('PUT', '/account/marketing-consent')
                ->withParsedBody(['enabled' => false]),
        );

        self::assertSame(403, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // Self-service export carries the consent and its delivery address
    // -------------------------------------------------------------------------

    public function test_export_includes_the_marketing_consent_record(): void
    {
        $userId = $this->insertUser('export-marketing@example.com');
        $this->conn->insert('marketing_consents', [
            'user_id'      => $userId,
            'status'       => 'confirmed',
            'source'       => 'signup',
            'text_version' => \Votepit\Domain\MarketingConsent::TEXT_VERSION,
            'requested_at' => '2026-10-26 10:00:00',
            'confirmed_at' => '2026-10-26 10:05:00',
        ]);
        $this->conn->update('users', [
            'marketing_email'             => 'export-marketing@example.com',
            'existing_customer_notice_at' => '2026-10-26 10:00:00',
        ], ['id' => $userId]);
        $app = $this->createApp(config: $this->marketingConfig());

        $response = $app->handle(
            (new ServerRequestFactory())->createServerRequest('GET', '/account/export')
                ->withCookieParams(['votepit_sess' => $this->sessionCookie($userId)]),
        );

        self::assertSame(200, $response->getStatusCode());
        $export = json_decode((string) $response->getBody(), true);
        self::assertSame(
            [
                'status'                      => 'confirmed',
                'source'                      => 'signup',
                'text_version'                => \Votepit\Domain\MarketingConsent::TEXT_VERSION,
                'requested_at'                => '2026-10-26 10:00:00',
                'confirmed_at'                => '2026-10-26 10:05:00',
                'withdrawn_at'                => null,
                'existing_customer_notice_at' => '2026-10-26 10:00:00',
                'marketing_email'             => 'export-marketing@example.com',
            ],
            $export['marketing_consent'],
        );
    }
}
