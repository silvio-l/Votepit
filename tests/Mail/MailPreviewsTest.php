<?php

declare(strict_types=1);

namespace Votepit\Tests\Mail;

use PHPUnit\Framework\TestCase;
use Votepit\Mail\InMemoryMailer;
use Votepit\Mail\Mailer;
use Votepit\Mail\MailPreviews;

final class MailPreviewsTest extends TestCase
{
    private const APP_URL   = 'https://app.example.test';
    private const RECIPIENT = 'owner@example.test';

    public function test_every_kind_renders_and_is_sent_exactly_once(): void
    {
        $mailer = new InMemoryMailer();

        $result = (new MailPreviews())->send($mailer, self::RECIPIENT, self::APP_URL);

        self::assertSame([], $result['failed']);
        self::assertSame(MailPreviews::kinds(), $result['sent']);
        self::assertSame(count(MailPreviews::kinds()), $mailer->count());

        foreach ($mailer->sent as $mail) {
            self::assertSame(self::RECIPIENT, $mail['to']);
            self::assertStringStartsWith(MailPreviews::SUBJECT_PREFIX, $mail['subject']);
            self::assertNotSame('', $mail['body']);
            self::assertIsString($mail['html']);
            self::assertNotNull($mail['image']);
            self::assertNull($mail['from_name'], 'unbranded previews keep the Votepit sender');
        }
    }

    public function test_links_point_only_at_the_configured_app_url(): void
    {
        $mailer = new InMemoryMailer();

        (new MailPreviews())->send($mailer, self::RECIPIENT, self::APP_URL);

        foreach ($mailer->sent as $mail) {
            preg_match_all('#https?://[^\s"<]+#', $mail['body'] . ' ' . $mail['html'], $matches);
            foreach ($matches[0] as $url) {
                self::assertStringStartsWith(self::APP_URL, $url, "link in '{$mail['subject']}' leaves app_url");
            }
        }
    }

    public function test_only_sends_the_requested_kinds(): void
    {
        $mailer = new InMemoryMailer();

        $result = (new MailPreviews())->send($mailer, self::RECIPIENT, self::APP_URL, ['login-link', 'cron-alert']);

        self::assertSame(['login-link', 'cron-alert'], $result['sent']);
        self::assertSame(2, $mailer->count());
    }

    public function test_an_unknown_kind_is_rejected_before_anything_is_sent(): void
    {
        $mailer = new InMemoryMailer();

        $this->expectException(\InvalidArgumentException::class);
        try {
            (new MailPreviews())->send($mailer, self::RECIPIENT, self::APP_URL, ['login-link', 'no-such-kind']);
        } finally {
            self::assertSame(0, $mailer->count());
        }
    }

    public function test_brand_adds_one_branded_variant_per_board_kind(): void
    {
        $mailer = new InMemoryMailer();

        $result = (new MailPreviews())->send($mailer, self::RECIPIENT, self::APP_URL, [], true);

        $branded = array_values(array_filter($result['sent'], static fn (string $label): bool => str_ends_with($label, ' (branded)')));
        self::assertCount(7, $branded);
        self::assertSame(count(MailPreviews::kinds()) + 7, $mailer->count());

        foreach ($mailer->sent as $mail) {
            if ($mail['from_name'] === null) {
                self::assertStringNotContainsString('[Brand]', $mail['subject']);
                continue;
            }
            self::assertSame('Demo Board via Votepit', $mail['from_name']);
            self::assertStringStartsWith(MailPreviews::SUBJECT_PREFIX . '[Brand] ', $mail['subject']);
            self::assertStringContainsString('#6d28d9', (string) $mail['html'], 'sample brand colour drives the button');
        }
    }

    public function test_a_failing_send_is_recorded_and_the_run_goes_on(): void
    {
        $mailer = new class () implements Mailer {
            public int $attempts = 0;

            public function send(
                string $toEmail,
                string $subject,
                string $textBody,
                ?string $htmlBody = null,
                ?\Votepit\Mail\InlineImage $inlineImage = null,
                ?string $fromName = null,
                array $headers = [],
            ): void {
                $this->attempts++;
                if ($this->attempts === 1) {
                    throw new \RuntimeException('smtp down');
                }
            }
        };

        $result = (new MailPreviews())->send($mailer, self::RECIPIENT, self::APP_URL, ['login-link', 'invite']);

        self::assertSame(['login-link' => 'smtp down'], $result['failed']);
        self::assertSame(['invite'], $result['sent']);
        self::assertSame(2, $mailer->attempts);
    }

    public function test_the_production_env_is_refused(): void
    {
        $this->expectException(\DomainException::class);

        MailPreviews::assertAllowed('prod', 'https://app.votepit.com'); // export-ok: app-host export-ok: subdomain-host
    }

    public function test_any_prod_installation_other_than_staging_is_refused(): void
    {
        $this->expectException(\DomainException::class);

        MailPreviews::assertAllowed('prod', 'https://feedback.example.com');
    }

    public function test_an_unknown_env_is_refused(): void
    {
        $this->expectException(\DomainException::class);

        MailPreviews::assertAllowed('production', 'https://app.votepit.com'); // export-ok: app-host export-ok: subdomain-host
    }

    public function test_a_host_that_only_starts_with_a_test_word_is_refused(): void
    {
        $this->expectException(\DomainException::class);

        MailPreviews::assertAllowed('prod', 'https://devious.example.com');
    }

    public function test_staging_and_dev_are_allowed(): void
    {
        MailPreviews::assertAllowed('prod', 'https://staging-app.votepit.com'); // export-ok: app-host export-ok: subdomain-host
        MailPreviews::assertAllowed('prod', 'https://staging.example.com');
        MailPreviews::assertAllowed('dev', 'http://localhost:8080');

        $this->addToAssertionCount(3);
    }

    public function test_the_dev_env_flag_never_unlocks_the_production_host(): void
    {
        // A production config mistakenly set to env "dev" must still be refused.
        $this->assertRefused('dev', 'https://app.votepit.com'); // export-ok: app-host export-ok: subdomain-host
        $this->assertRefused('dev', 'https://votepit.com');
    }

    public function test_every_spelling_of_the_production_host_is_refused(): void
    {
        foreach (['https://APP.votepit.com/', 'https://app.votepit.com:443/', 'https://app.votepit.com./', 'https://App.Votepit.com:8443/x', 'https://www.votepit.com/', 'https://votepit.com'] as $url) { // export-ok: app-host export-ok: subdomain-host
            $this->assertRefused('prod', $url);
            $this->assertRefused('dev', $url);
        }
    }

    public function test_a_missing_or_unparsable_app_url_is_refused_in_every_env(): void
    {
        foreach (['', 'not a url', 'https:///staging.votepit.com'] as $url) { // export-ok: subdomain-host
            $this->assertRefused('prod', $url);
            $this->assertRefused('dev', $url);
        }
    }

    public function test_test_host_spellings_with_case_port_and_slashes_are_allowed(): void
    {
        MailPreviews::assertAllowed('prod', 'https://Staging-App.Votepit.com:8443/'); // export-ok: app-host export-ok: subdomain-host
        MailPreviews::assertAllowed('prod', 'https://STAGING.example.com/');
        MailPreviews::assertAllowed('prod', 'https://qa-1.example.com.');
        MailPreviews::assertAllowed('dev', 'https://app.votepit.test');

        $this->addToAssertionCount(4);
    }

    public function test_a_production_look_alike_is_refused_in_prod(): void
    {
        $this->assertRefused('prod', 'https://app.votepit.com.evil.example'); // export-ok: app-host export-ok: subdomain-host
        $this->assertRefused('prod', 'https://app-votepit.com');
        $this->assertRefused('prod', 'https://feedback.example.com:443/');
    }

    private function assertRefused(string $env, string $appUrl): void
    {
        try {
            MailPreviews::assertAllowed($env, $appUrl);
        } catch (\DomainException) {
            $this->addToAssertionCount(1);

            return;
        }

        self::fail(sprintf('mail previews must be refused for env "%s" and app_url "%s"', $env, $appUrl));
    }
}
