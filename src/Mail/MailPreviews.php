<?php

declare(strict_types=1);

namespace Votepit\Mail;

use Votepit\Cron\AlertDecision;
use Votepit\Cron\CatchUpPolicy;
use Votepit\Cron\CronAlerter;
use Votepit\Cron\CronJobDefinition;
use Votepit\Digest\NewIdeasDigestMail;
use Votepit\Digest\ParticipantDigestMail;
use Votepit\Digest\WeeklyDigestMail;
use Votepit\Domain\IdeaStatusChanger;
use Votepit\Http\Action\AbuseReportAction;
use Votepit\Http\Action\BoardSmtpTestAction;
use Votepit\Http\Action\CommentCreateAction;
use Votepit\Http\Action\CommentModerationAction;
use Votepit\Http\Action\IdeaMergeAction;
use Votepit\Http\Action\IdeaModerationAction;
use Votepit\Http\Action\InviteAction;
use Votepit\Http\Action\MarketingConsentAction;
use Votepit\Http\Action\NotificationPreferencesAction;
use Votepit\Http\Action\SupportRequestAction;
use Votepit\Http\Routes\AuthRoutes;
use Votepit\Nudge\OperatorNudgeMail;
use Votepit\Persistence\CronJobState;
use Votepit\Security\PasswordResetMailer;

/**
 * Sample-content previews of every transactional mail kind (bin/send-mail-previews.php).
 *
 * Every kind is rendered by the SAME builder the production code path uses
 * (the compose()/notice helpers next to each sender), so the design and
 * wording are the real ones; only the content is fake ("Demo Board", account
 * "demo", dummy tokens that authenticate nothing). Renderers are called
 * directly: no per-user digest eligibility, no notification rate bucket, no
 * database lookups. Sending goes through the real Mailer the caller passes in
 * (production: SmtpConfigResolver::buildMailer(), i.e. SMTP circuit breaker and
 * volume monitor stay in the chain).
 *
 * Subjects are always prefixed with SUBJECT_PREFIX so previews cannot be
 * mistaken for real mail in the owner's inbox.
 *
 * @phpstan-import-type DigestSection from WeeklyDigestMail
 */
final class MailPreviews
{
    public const SUBJECT_PREFIX = '[Preview] ';

    /**
     * The one non-dev installation allowed to receive previews. Staging runs
     * with config env `prod` (it mirrors production behaviour), so the host is
     * what tells it apart from production.
     */
    /** First host label of a test installation: "staging", "staging-app", "dev-eu", … */
    private const TEST_HOST_LABEL = '/^(staging|dev|test|qa|preview)(-[a-z0-9-]+)?$/D';

    /**
     * Production hosts (lower case, no trailing dot): refused in every env.
     * The apex and www serve the marketing site and the app host serves the
     * tenants; neither may ever receive a preview run.
     */
    private const PRODUCTION_HOSTS = ['app.votepit.com', 'votepit.com', 'www.votepit.com']; // export-ok: app-host export-ok: subdomain-host

    /** Sample brand for --brand variants: validated hex colours, CTA contrast checked by MailBrand. */
    private const BRAND_RULE_COLOR = '#7c3aed';
    private const BRAND_CTA_COLOR  = '#6d28d9';

    private const DEMO_BOARD      = 'Demo Board';
    private const DEMO_BOARD_SLUG = 'demo-board';
    private const DEMO_ACCOUNT    = 'demo';
    private const DEMO_IDEA       = 'Sample idea: dark mode for the dashboard';
    private const DUMMY_TOKEN     = 'preview-dummy-token-not-valid';

    /** @var list<string> every kind, in send order */
    private const KINDS = [
        'login-link',
        'password-reset',
        'invite',
        'notification-email-confirm',
        'marketing-consent-confirm',
        'marketing-consent-confirm-de',
        'account-deletion-reminder',
        'compliance-reminder',
        'idea-comment',
        'thread-reply',
        'idea-status',
        'idea-merged',
        'idea-removed',
        'comment-removed',
        'abuse-report',
        'support-request',
        'support-reply',
        'weekly-digest',
        'weekly-digest-de',
        'participant-digest',
        'participant-digest-de',
        'new-ideas',
        'operator-onboarding',
        'operator-onboarding-vote',
        'operator-quiet-board',
        'cron-alert',
        'smtp-test',
    ];

    /** @var list<string> kinds that are board-bound and therefore get a board-branded variant with --brand */
    private const BRANDED_KINDS = [
        'idea-comment',
        'thread-reply',
        'idea-status',
        'idea-merged',
        'idea-removed',
        'comment-removed',
        'abuse-report',
    ];

    /** @return list<string> */
    public static function kinds(): array
    {
        return self::KINDS;
    }

    /**
     * Fail-secure gate: previews only run on dev or on a test installation.
     *
     * Config env accepts only `prod` and `dev`, and staging also uses `prod`,
     * so a `prod` installation is allowed only when the first label of its
     * app_url host marks it as a test host (staging, dev, test, qa, preview,
     * optionally with a "-suffix"); everything else, production included,
     * is refused. The production hosts (PRODUCTION_HOSTS) and a missing or
     * unparsable app_url are refused for every env, including "dev".
     *
     * @throws \DomainException
     */
    public static function assertAllowed(string $env, string $appUrl): void
    {
        $parsed = parse_url($appUrl, PHP_URL_HOST);
        // Case and a trailing dot are the same host (a dotted production host is still production).
        $host   = is_string($parsed) ? rtrim(strtolower($parsed), '.') : '';

        // Checked before the env exemption: a production host stays refused even
        // if its config was mistakenly set to env "dev". A host that cannot be
        // determined (missing or unparsable app_url) is refused in every env.
        if ($host === '' || in_array($host, self::PRODUCTION_HOSTS, true)) {
            throw new \DomainException(sprintf(
                'mail previews are refused here (env "%s", app_url host "%s"): they never run on the production host or without a known app_url.',
                $env,
                $host === '' ? '(none)' : $host,
            ));
        }

        if ($env === 'dev') {
            return;
        }

        $label = explode('.', $host)[0];
        if ($env === 'prod' && preg_match(self::TEST_HOST_LABEL, $label) === 1) {
            return;
        }

        throw new \DomainException(sprintf(
            'mail previews are refused here (env "%s", app_url host "%s"): they run on dev or on a staging/test host only.',
            $env,
            $host,
        ));
    }

    /**
     * Sends one sample per kind (or only the given kinds) to $recipient.
     *
     * With $brand, each board-bound kind is additionally sent once with the
     * sample board branding (sender "Demo Board via Votepit", coloured rule and
     * button). A failing send is recorded and does not stop the run.
     *
     * @param list<string> $only kinds to send; empty = all
     *
     * @return array{sent: list<string>, failed: array<string, string>}
     *
     * @throws \InvalidArgumentException on an unknown kind in $only
     */
    public function send(Mailer $mailer, string $recipient, string $appUrl, array $only = [], bool $brand = false): array
    {
        $unknown = array_values(array_diff($only, self::KINDS));
        if ($unknown !== []) {
            throw new \InvalidArgumentException('unknown mail kind(s): ' . implode(', ', $unknown));
        }

        $brandedVariant = $brand
            ? MailBrand::fromColors(self::BRAND_RULE_COLOR, self::BRAND_CTA_COLOR, MailTemplate::COLOR_INK, MailTemplate::COLOR_CTA)
            : null;

        $sent   = [];
        $failed = [];
        foreach (self::KINDS as $kind) {
            if ($only !== [] && !in_array($kind, $only, true)) {
                continue;
            }

            $variants = [null];
            if ($brandedVariant instanceof \Votepit\Mail\MailBrand && in_array($kind, self::BRANDED_KINDS, true)) {
                $variants[] = $brandedVariant;
            }

            foreach ($variants as $variantBrand) {
                $label = $kind . ($variantBrand instanceof \Votepit\Mail\MailBrand ? ' (branded)' : '');
                try {
                    $mail = $this->build($kind, $appUrl, $variantBrand);
                    $mailer->send($recipient, $mail['subject'], $mail['text'], $mail['html'], $mail['image'], $mail['from_name']);
                    $sent[] = $label;
                } catch (\Throwable $e) {
                    $failed[$label] = $e->getMessage();
                }
            }
        }

        return ['sent' => $sent, 'failed' => $failed];
    }

    /**
     * @return array{subject: string, text: string, html: ?string, image: ?InlineImage, from_name: ?string}
     */
    private function build(string $kind, string $appUrl, ?MailBrand $brand): array
    {
        $boardPath = '/' . self::DEMO_ACCOUNT . '/' . self::DEMO_BOARD_SLUG;
        $ideaPath  = $boardPath . '/idea/1';

        $mail = match ($kind) {
            'login-link' => AuthRoutes::loginMail($appUrl . '/login/verify?token=' . self::DUMMY_TOKEN),
            'password-reset' => PasswordResetMailer::compose($appUrl . '/password/reset/confirm?token=' . self::DUMMY_TOKEN),
            'invite' => InviteAction::compose('preview@example.com', $appUrl . '/invite/accept?token=' . self::DUMMY_TOKEN),
            'notification-email-confirm' => NotificationPreferencesAction::confirmEmailMail(
                $appUrl . '/account/notification-email/confirm?token=' . self::DUMMY_TOKEN,
            ),
            'marketing-consent-confirm' => MarketingConsentAction::confirmMail(
                $appUrl . '/account/marketing-consent/confirm?token=' . self::DUMMY_TOKEN,
            ),
            'marketing-consent-confirm-de' => MarketingConsentAction::confirmMail(
                $appUrl . '/account/marketing-consent/confirm?token=' . self::DUMMY_TOKEN,
                'de',
            ),
            'account-deletion-reminder' => AuthRoutes::deletionReminderMail(new \DateTimeImmutable('+30 days')),
            'compliance-reminder' => AuthRoutes::complianceReminderMail(2),
            'idea-comment' => $this->notification(CommentCreateAction::ideaCommentNotice(self::DEMO_IDEA), $appUrl, $ideaPath . '#comment-1', $brand),
            'thread-reply' => $this->notification(CommentCreateAction::threadReplyNotice(self::DEMO_IDEA), $appUrl, $ideaPath . '#comment-2', $brand),
            'idea-status' => $this->notification(['title' => IdeaStatusChanger::NOTIFY_TITLE, 'body' => IdeaStatusChanger::statusNotifyBody(self::DEMO_IDEA, 'Planned', 'Sample reason for the status change.')], $appUrl, $ideaPath, $brand),
            'idea-merged' => $this->notification(['title' => IdeaMergeAction::NOTIFY_TITLE, 'body' => IdeaMergeAction::mergedNotifyBody('Sample duplicate idea', self::DEMO_IDEA)], $appUrl, $ideaPath, $brand),
            'idea-removed' => $this->notification(IdeaModerationAction::removedNotice('community_rule'), $appUrl, '/appeal/1', $brand),
            'comment-removed' => $this->notification(CommentModerationAction::removedNotice('community_rule'), $appUrl, '/appeal/2', $brand),
            'abuse-report' => $this->notification(['title' => AbuseReportAction::NOTIFY_TITLE, 'body' => 'Sample report reason (preview, not a real report).'], $appUrl, '/operator', $brand),
            'support-request' => $this->notification(SupportRequestAction::newRequestNotice('Sample support subject'), $appUrl, '/operator/support/1', null),
            'support-reply' => $this->notification(SupportRequestAction::newReplyNotice('Sample support subject'), $appUrl, '/operator/support/1', null),
            'weekly-digest' => WeeklyDigestMail::render('en', $this->digestSections($appUrl, $boardPath), $appUrl . '/', $appUrl . '/profile', $appUrl . '/digest/unsubscribe?token=' . self::DUMMY_TOKEN, new \DateTimeImmutable('-7 days'), new \DateTimeImmutable('-1 day')),
            'weekly-digest-de' => WeeklyDigestMail::render('de', $this->digestSections($appUrl, $boardPath), $appUrl . '/', $appUrl . '/profile', $appUrl . '/digest/unsubscribe?token=' . self::DUMMY_TOKEN, new \DateTimeImmutable('-7 days'), new \DateTimeImmutable('-1 day')),
            'participant-digest' => $this->participantDigest('en', $appUrl, $ideaPath),
            'participant-digest-de' => $this->participantDigest('de', $appUrl, $ideaPath),
            'new-ideas' => NewIdeasDigestMail::render('en', $this->newIdeasSections($appUrl, $boardPath), $appUrl, $appUrl . '/profile', $appUrl . '/mail/unsubscribe?token=' . self::DUMMY_TOKEN, null),
            'operator-onboarding' => OperatorNudgeMail::onboardingFirstIdea('en', self::DEMO_BOARD, $appUrl . $boardPath, self::DEMO_ACCOUNT, $appUrl . '/operator-nudges/unsubscribe?token=' . self::DUMMY_TOKEN, $appUrl . '/profile'),
            'operator-onboarding-vote' => OperatorNudgeMail::onboardingFirstVote('en', self::DEMO_BOARD, $appUrl . $boardPath, self::DEMO_ACCOUNT, $appUrl . '/operator-nudges/unsubscribe?token=' . self::DUMMY_TOKEN, $appUrl . '/profile'),
            'operator-quiet-board' => OperatorNudgeMail::quietBoard('en', self::DEMO_BOARD, $appUrl . $boardPath, self::DEMO_ACCOUNT, $appUrl . '/operator-nudges/unsubscribe?token=' . self::DUMMY_TOKEN, $appUrl . '/profile'),
            'cron-alert' => $this->cronAlert(),
            'smtp-test' => BoardSmtpTestAction::testMail(self::DEMO_BOARD_SLUG),
            default => throw new \LogicException('no builder for mail kind ' . $kind),
        };

        $brandPrefix = $brand instanceof \Votepit\Mail\MailBrand ? '[Brand] ' : '';

        return [
            'subject'   => self::SUBJECT_PREFIX . $brandPrefix . $mail['subject'],
            'text'      => $mail['text'],
            'html'      => $mail['html'],
            'image'     => $mail['image'],
            'from_name' => $brand instanceof \Votepit\Mail\MailBrand ? MailSenderName::forName(self::DEMO_BOARD) : null,
        ];
    }

    /**
     * @param array{title: string, body: string} $notice
     *
     * @return array{subject: string, text: string, html: string, image: InlineImage}
     */
    private function notification(array $notice, string $appUrl, string $linkPath, ?MailBrand $brand): array
    {
        return ['subject' => $notice['title'], ...CommentNotificationMailer::compose($appUrl, $notice['title'], $notice['body'], $linkPath, $brand)];
    }

    /**
     * Two sample items (a status change with a reason, a new comment), so the
     * digest shows its multi-item layout, as a real batch would.
     *
     * @return array{subject: string, text: string, html: string, image: InlineImage}
     */
    private function participantDigest(string $language, string $appUrl, string $ideaPath): array
    {
        return ParticipantDigestMail::render(
            $language,
            [
                ['event_kind' => ParticipantDigestMail::EVENT_STATUS, 'title' => self::DEMO_IDEA, 'status' => 'Planned', 'reason' => 'Sample reason for the status change.', 'excerpt' => null, 'link' => $appUrl . $ideaPath],
                ['event_kind' => ParticipantDigestMail::EVENT_COMMENT, 'title' => 'Sample idea: new export format', 'status' => null, 'reason' => null, 'excerpt' => null, 'link' => $appUrl . '/' . self::DEMO_ACCOUNT . '/' . self::DEMO_BOARD_SLUG . '/idea/2'],
            ],
            $appUrl . '/profile',
            $appUrl . '/mail/unsubscribe?token=' . self::DUMMY_TOKEN,
            $appUrl,
            false,
            null,
        );
    }

    /**
     * @return list<array{board_name: string, board_url: string, ideas: list<array{id: int, title: string}>}>
     */
    private function newIdeasSections(string $appUrl, string $boardPath): array
    {
        return [[
            'board_name' => self::DEMO_BOARD,
            'board_url'  => $appUrl . $boardPath,
            'ideas'      => [
                ['id' => 1, 'title' => 'Sample idea: new export format'],
                ['id' => 2, 'title' => self::DEMO_IDEA],
            ],
        ]];
    }

    /**
     * @return list<DigestSection>
     */
    private function digestSections(string $appUrl, string $boardPath): array
    {
        return [[
            'account_name' => self::DEMO_ACCOUNT,
            'board_name'   => self::DEMO_BOARD,
            'board_url'    => $appUrl . $boardPath,
            'admin_url'    => $appUrl . '/' . self::DEMO_ACCOUNT . '/admin/boards/' . self::DEMO_BOARD_SLUG,
            'stats'        => ['weekly_votes' => 12, 'weekly_new_ideas' => 3, 'avg_consensus' => 64, 'total_ideas' => 20],
            'highlights'   => [
                'new_ideas'      => [['id' => 1, 'title' => 'Sample idea: new export format']],
                'top'            => [['id' => 2, 'title' => self::DEMO_IDEA, 'up_count' => 9, 'down_count' => 1]],
                'most_rejected'  => [['id' => 3, 'title' => 'Sample idea: rejected example', 'up_count' => 1, 'down_count' => 7]],
                'most_polarising' => [['id' => 4, 'title' => 'Sample idea: split opinions', 'up_count' => 5, 'down_count' => 5]],
            ],
            'awaiting_answer' => 2,
        ], [
            // A second, quiet board: shows the compact section.
            'account_name' => self::DEMO_ACCOUNT,
            'board_name'   => 'Sample roadmap',
            'board_url'    => $appUrl . '/' . self::DEMO_ACCOUNT . '/sample-roadmap',
            'admin_url'    => $appUrl . '/' . self::DEMO_ACCOUNT . '/admin/boards/sample-roadmap',
            'stats'        => ['weekly_votes' => 4, 'weekly_new_ideas' => 1, 'avg_consensus' => 75, 'total_ideas' => 6],
            'highlights'   => [
                'new_ideas'      => [['id' => 5, 'title' => 'Sample idea: show quarterly goals']],
                'top'            => [['id' => 5, 'title' => 'Sample idea: show quarterly goals', 'up_count' => 3, 'down_count' => 1]],
                'most_rejected'  => [],
                'most_polarising' => [],
            ],
            'awaiting_answer' => 0,
        ]];
    }

    /**
     * @return array{subject: string, text: string, html: ?string, image: ?InlineImage}
     */
    private function cronAlert(): array
    {
        $job = new CronJobDefinition(
            'demo-job',
            ['php', 'bin/demo-job.php'],
            '*/5 * * * *',
            CatchUpPolicy::None,
            600,
            'core',
        );
        $state = new CronJobState(
            'demo-job',
            null,
            null,
            null,
            null,
            new \DateTimeImmutable('2026-01-01 03:00:00'),
            1,
            3,
            'failing',
            null,
        );
        $changes = [['job' => $job, 'state' => $state, 'decision' => AlertDecision::Alert]];

        return ['subject' => CronAlerter::subject($changes), ...CronAlerter::body($changes)];
    }
}
