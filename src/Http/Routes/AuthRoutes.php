<?php

declare(strict_types=1);

namespace Votepit\Http\Routes;

use Doctrine\DBAL\Connection;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Psr7\Factory\ResponseFactory;
use Votepit\Config;
use Votepit\Domain\MarketingConsent;
use Votepit\Http\Action\Login2faAction;
use Votepit\Http\Action\LoginPasswordAction;
use Votepit\Http\Action\LoginVerifyAction;
use Votepit\Http\Action\OAuthCallbackAction;
use Votepit\Http\Action\OAuthStartAction;
use Votepit\Http\Action\PasswordResetConfirmAction;
use Votepit\Http\Action\PasswordResetRequestAction;
use Votepit\Http\CoreRoute;
use Votepit\Http\Middleware\AccountContextMiddleware;
use Votepit\Http\Middleware\AuthNMiddleware;
use Votepit\Http\Middleware\AuthZMiddleware;
use Votepit\Http\Middleware\RateLimitMiddleware;
use Votepit\Http\Support\LoginBoardResolver;
use Votepit\Logging\AuditLogger;
use Votepit\Logging\AuthzDenialLogger;
use Votepit\Mail\Mailer;
use Votepit\Mail\MailTemplate;
use Votepit\Mail\SmtpConfigResolver;
use Votepit\Monitoring\ErrorReporter;
use Votepit\Persistence\AccountMemberRepository;
use Votepit\Persistence\AccountRepository;
use Votepit\Persistence\BoardRepository;
use Votepit\Persistence\LoginTokenRepository;
use Votepit\Persistence\MarketingConsentRepository;
use Votepit\Persistence\OAuthIdentityRepository;
use Votepit\Persistence\OAuthStateRepository;
use Votepit\Persistence\TotpBackupCodeRepository;
use Votepit\Persistence\UserRepository;
use Votepit\Security\AuditIpHasher;
use Votepit\Security\ClientIp;
use Votepit\Security\EncryptionService;
use Votepit\Security\IdentityHasher;
use Votepit\Security\LoginSessionIssuer;
use Votepit\Security\OAuth\OAuthHttpClient;
use Votepit\Security\Password\PasswordPolicy;
use Votepit\Security\PasswordResetMailer;
use Votepit\Security\RateLimiter;
use Votepit\Security\ReturnToValidator;
use Votepit\Security\SessionService;
use Votepit\Security\TokenVault;
use Votepit\Security\Totp;

/**
 * Global (unprefixed) authentication routes: magic-link login/logout,
 * password login, TOTP second step, password reset and OAuth login.
 */
final class AuthRoutes
{
    /**
     * Magic-link login/logout + password login + TOTP 2FA step +
     * password reset + OAuth login.
     *
     * @param App<null> $app
     * @param array{limit: int, window: int} $emailRateLimit
     * @param array{limit: int, window: int} $mlIpRateLimit
     */
    public static function register(
        App $app,
        Config $config,
        ResponseFactory $responseFactory,
        Connection $conn,
        AuditLogger $audit,
        AuthzDenialLogger $authzDenialLogger,
        AuditIpHasher $auditIpHasher,
        SessionService $sessions,
        UserRepository $userRepo,
        IdentityHasher $hasher,
        LoginTokenRepository $tokenRepo,
        TokenVault $vault,
        ?Mailer $mailer,
        SmtpConfigResolver $smtpResolver,
        BoardRepository $boardRepo,
        AccountMemberRepository $accountMemberRepo,
        AccountRepository $accountRepoForPlan,
        LoginSessionIssuer $loginSessionIssuer,
        Totp $totp,
        EncryptionService $totpEncryption,
        TotpBackupCodeRepository $totpBackupCodeRepo,
        PasswordResetMailer $passwordResetMailer,
        array $emailRateLimit,
        array $mlIpRateLimit,
        OAuthHttpClient $oauthHttp,
        PasswordPolicy $passwordPolicy,
        ErrorReporter $reporter,
        MarketingConsentRepository $marketing,
    ): void {
        // GET /login — SPA route: returns validated return_to path (AuthZ: anon).
        $app->get('/login', function (
            ServerRequestInterface $request,
            ResponseInterface $response,
        ): ResponseInterface {
            $params   = $request->getQueryParams();
            $rawR     = is_string($params['r'] ?? null) ? $params['r'] : '';
            $returnTo = ReturnToValidator::isValid($rawR) ? $rawR : '';
            $response->getBody()->write((string) json_encode([
                'ok'        => true,
                'return_to' => $returnTo,
            ]));
            return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
        })->add(AuthZMiddleware::anon($responseFactory));

        // POST /login — processes the email, sends the magic link (AuthZ: anon).
        // Response is ALWAYS identical (anti-enumeration; AC 3 & 4).
        $app->post('/login', function (
            ServerRequestInterface $request,
            ResponseInterface $response,
        ) use ($userRepo, $hasher, $tokenRepo, $vault, $mailer, $smtpResolver, $boardRepo, $audit, $config, $accountMemberRepo, $accountRepoForPlan, $marketing): ResponseInterface {
            $parsed    = $request->getParsedBody();
            $rawEmail  = is_array($parsed) ? (string) ($parsed['email'] ?? '') : '';
            $email     = strtolower(trim($rawEmail));
            $rawR      = is_array($parsed) ? (string) ($parsed['r'] ?? '') : '';
            $returnTo  = ReturnToValidator::isValid($rawR) ? $rawR : '';
            // Optional product-news checkbox of the signup e-mail step (W1). Only
            // honoured where marketing is enabled, and only on the signup request.
            $productNewsTicked = is_array($parsed) && filter_var($parsed['product_news'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $marketingSignup   = $config->marketingConsentEnabled && $returnTo === '/signup/account';

            // Only send when syntax is valid — neutral handling (no 4xx).
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
                // Email is only held transiently for hashing + mail sending (ADR 0002) —
                // never stored in the DB; findByEmailHmac()/create() only ever see the HMAC.
                $emailHmac = $hasher->hash($email);
                $user      = $userRepo->findByEmailHmac($emailHmac) ?? $userRepo->create($emailHmac);

                // Marketing consent on the signup step (migration 0112). Nothing is
                // proven by this request: the § 7 Abs. 3 notice and the delivery address
                // are recorded only when the magic link is REDEEMED (LoginVerifyAction).
                // Here the address rides on the token row, and a ticked, not yet confirmed
                // product-news checkbox becomes pending; redeeming the link confirms it.
                $confirmsProductNews  = false;
                $signupMarketingEmail = null;
                if ($marketingSignup) {
                    $now                  = new \DateTimeImmutable();
                    $signupMarketingEmail = $email;
                    $tokenRepo->clearExpiredSignupEmails($now);
                    $consent = $marketing->findForUser((int) $user['id']);
                    if ($productNewsTicked && ($consent['status'] ?? null) !== MarketingConsent::STATUS_CONFIRMED) {
                        $marketing->requestConfirmation(
                            (int) $user['id'],
                            MarketingConsent::SOURCE_SIGNUP,
                            null,
                            null,
                            $now,
                        );
                        $confirmsProductNews = true;
                    }
                }

                $tokenRepo->deleteOpenForUser((int) $user['id']);

                $pair      = $vault->generate();
                $expiresAt = (new \DateTimeImmutable('+' . $config->magicLinkTtl . ' seconds'))
                    ->format('Y-m-d H:i:s');
                $tokenRepo->insert(
                    (int) $user['id'],
                    $pair['hash'],
                    $expiresAt,
                    'login',
                    $confirmsProductNews,
                    $signupMarketingEmail,
                );
                if ($config->marketingConsentEnabled) {
                    // After the insert, so the link just issued keeps its own pending consent.
                    $marketing->deleteUnconfirmableSignupPending(new \DateTimeImmutable());
                }

                $link = $config->appUrl . '/login/verify?token=' . $pair['token'];
                if ($returnTo !== '') {
                    $link .= '&r=' . rawurlencode($returnTo);
                }

                // Resolve per-board SMTP: see LoginBoardResolver's class doc — cloud-mode
                // returnTo is /{accountSlug}/{boardSlug}/..., so the account can't come from
                // the request attribute here (/login has no {account} route argument, that
                // attribute always falls back to defaultAccountId()) and must be parsed out
                // of returnTo itself.
                $boardId = LoginBoardResolver::resolve(
                    $returnTo,
                    $config->routingMode,
                    $boardRepo,
                    $accountRepoForPlan,
                    (int) $request->getAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID),
                );
                $mailToUse = $mailer ?? $smtpResolver->buildMailer($boardId);

                $loginMail = self::loginMail($link, $confirmsProductNews);
                $mailToUse->send(
                    $email,
                    $loginMail['subject'],
                    $loginMail['text'],
                    $loginMail['html'],
                    $loginMail['image'],
                );

                // Only the pseudonymized email_hmac in the log (ADR 0002) — plaintext email
                // and plaintext token must NEVER go into the log.
                $audit->log('magic_link.requested', ['email_hmac' => $emailHmac]);

                // Scheduled-deletion export reminder: piggy-back the reminder
                // mail onto this exact request —
                // the ONLY moment this codebase ever holds $email in plaintext
                // (ADR 0002, see AccountMemberRepository::ownedAccountsPendingReminder()
                // class doc for the full rationale). Deliberately a SEPARATE mail
                // from the login link above (a distinct subject a user can search
                // for), sent to every account this user owns with a still-pending,
                // not-yet-notified deletion schedule.
                foreach ($accountMemberRepo->ownedAccountsPendingReminder((int) $user['id']) as $pending) {
                    $deadline = new \DateTimeImmutable($pending['deletion_scheduled_at']);
                    $reminderMail = self::deletionReminderMail($deadline);
                    $mailToUse->send(
                        $email,
                        $reminderMail['subject'],
                        $reminderMail['text'],
                        $reminderMail['html'],
                        $reminderMail['image'],
                    );
                    $accountRepoForPlan->markDeletionReminderSent($pending['account_id']);
                    $audit->log('account.deletion_reminder_sent', ['account_id' => $pending['account_id']]);
                }

                // Board-operator-compliance reminder: same piggyback pattern
                // as the deletion reminder above (ADR 0002 — this request is
                // the only moment $email is held in plaintext). Sent to every
                // account this user owns that currently has at least one
                // board frozen for non-compliance and hasn't already been
                // reminded since the freeze took hold (see
                // AccountMemberRepository::ownedAccountsWithComplianceReminderPending()
                // class doc).
                foreach ($accountMemberRepo->ownedAccountsWithComplianceReminderPending((int) $user['id']) as $pending) {
                    $complianceMail = self::complianceReminderMail($pending['frozen_board_count']);
                    $mailToUse->send(
                        $email,
                        $complianceMail['subject'],
                        $complianceMail['text'],
                        $complianceMail['html'],
                        $complianceMail['image'],
                    );
                    $accountRepoForPlan->markComplianceReminderSent($pending['account_id']);
                    $audit->log('account.compliance_reminder_sent', [
                        'account_id'          => $pending['account_id'],
                        'frozen_board_count'  => $pending['frozen_board_count'],
                    ]);
                }
            }

            $response->getBody()->write((string) json_encode(['ok' => true]));
            return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
        })
        ->setName(CoreRoute::LOGIN_REQUEST)
        ->add(AuthZMiddleware::anon($responseFactory))
        ->add(RateLimitMiddleware::perAction(
            new RateLimiter($conn),
            $responseFactory,
            'magiclink:email',
            $emailRateLimit['limit'],
            $emailRateLimit['window'],
            static function (ServerRequestInterface $r) use ($hasher): ?string {
                $parsed = $r->getParsedBody();
                $email  = is_array($parsed) ? trim((string) ($parsed['email'] ?? '')) : '';
                return $email !== '' ? $hasher->hash($email) : null;
            },
            static function (string $emailHmac) use ($audit): void {
                $audit->log('magiclink.rate_limited', ['email_hmac' => $emailHmac]);
            },
        ))
        ->add(RateLimitMiddleware::perAction(
            new RateLimiter($conn),
            $responseFactory,
            'magiclink:ip',
            $mlIpRateLimit['limit'],
            $mlIpRateLimit['window'],
            static fn (ServerRequestInterface $r): ?string => ClientIp::resolve($r, $config->trustCloudflareIp),
            static function (string $ip) use ($audit, $auditIpHasher): void {
                $audit->log('magiclink.rate_limited', ['ip_hmac' => $auditIpHasher->hash($ip)]);
            },
        ));

        // POST /logout — bumps token_version (invalidates all sessions) + clears the cookie.
        // AuthZ: user (anon → 401); CSRF: mutating verb → globally enforced.
        $app->post('/logout', function (
            ServerRequestInterface $request,
            ResponseInterface $response,
        ) use ($userRepo, $sessions, $audit): ResponseInterface {
            /** @var array<string, mixed>|null $user */
            $user = $request->getAttribute(AuthNMiddleware::ATTR_USER);
            if (is_array($user)) {
                $userRepo->bumpTokenVersion((int) $user['id']);
                $audit->log('user.logout', ['uid' => (int) $user['id']]);
            }
            $response->getBody()->write((string) json_encode(['ok' => true]));
            return $sessions->clear(
                $response->withStatus(200)->withHeader('Content-Type', 'application/json')
            );
        })->add(AuthZMiddleware::user($responseFactory, $authzDenialLogger));

        // GET /login/verify?token=<plaintext> — verifies the magic link and
        // issues a fresh session (AuthZ: anon, GET → CSRF-exempt:
        // the one-time token itself is the capability). On failure NO
        // side effect, uniform 4xx JSON error response.
        $app->get('/login/verify', new LoginVerifyAction($userRepo, $tokenRepo, $vault, $audit, $config, $loginSessionIssuer, $conn, $accountMemberRepo, $marketing))
            ->add(AuthZMiddleware::anon($responseFactory));

        // POST /login/password — email + password login (AuthZ: anon), additive
        // to the magic link (which stays always available). With active TOTP: no
        // session issued, instead a pending-2FA token (see LoginPasswordAction).
        $passwordRateLimit  = $config->rateLimit('login:password');
        $passwordRateLimiter = new RateLimiter($conn);
        // Second dimension alongside the per-IP bucket above (issue 05): an
        // attacker rotating IPs against one known account is still caught by
        // this email-HMAC-keyed bucket — same identity the action itself
        // hashes the submitted email into, never the plaintext email.
        $passwordEmailRateLimit = $config->rateLimit('login:password:email');
        $app->post('/login/password', new LoginPasswordAction($userRepo, $hasher, $tokenRepo, $vault, $loginSessionIssuer, $audit, $auditIpHasher, $passwordRateLimiter, $config->trustCloudflareIp))
            ->add(AuthZMiddleware::anon($responseFactory))
            ->add(RateLimitMiddleware::perIp(
                $passwordRateLimiter,
                $responseFactory,
                'login:password',
                $passwordRateLimit['limit'],
                $passwordRateLimit['window'],
                $config->trustCloudflareIp,
                static function (string $ip) use ($audit, $auditIpHasher): void {
                    $audit->log('login_password.rate_limited', ['ip_hmac' => $auditIpHasher->hash($ip)]);
                },
            ))
            ->add(RateLimitMiddleware::perAction(
                $passwordRateLimiter,
                $responseFactory,
                'login:password:email',
                $passwordEmailRateLimit['limit'],
                $passwordEmailRateLimit['window'],
                static function (ServerRequestInterface $r) use ($hasher): ?string {
                    $parsed = $r->getParsedBody();
                    $email  = is_array($parsed) ? strtolower(trim((string) ($parsed['email'] ?? ''))) : '';
                    return $email !== '' ? $hasher->hash($email) : null;
                },
                static function (string $emailHmac) use ($audit, $reporter): void {
                    $audit->log('login_password.rate_limited', ['email_hmac' => $emailHmac]);
                    // Sentry mirror (OWASP/BSI audit remediation issue 12):
                    // sustained failed logins against one specific account —
                    // deliberately the identity-keyed bucket, not the
                    // noisier per-IP one (free-tier quota discipline).
                    $reporter->reportSecurityEvent('login_password.rate_limited', 'warning', ['email_hmac' => $emailHmac]);
                },
            ));

        // POST /login/2fa — second step after magic-link/password login with
        // active TOTP (AuthZ: anon — the pending-token capability IS the
        // authentication at this point, analogous to /login/verify).
        $twoFaRateLimit  = $config->rateLimit('login:2fa');
        $twoFaRateLimiter = new RateLimiter($conn);
        // Second dimension alongside the per-IP bucket above (issue 05): an
        // attacker rotating IPs against one pending login is still caught by
        // this bucket, keyed by the SAME hash of the pending token the action
        // itself looks the token up by — never the plaintext token.
        $twoFaTokenRateLimit = $config->rateLimit('login:2fa:token');
        $app->post('/login/2fa', new Login2faAction($tokenRepo, $vault, $userRepo, $totpBackupCodeRepo, $totp, $totpEncryption, $loginSessionIssuer, $audit, $auditIpHasher, $twoFaRateLimiter, $config->trustCloudflareIp))
            ->add(AuthZMiddleware::anon($responseFactory))
            ->add(RateLimitMiddleware::perIp(
                $twoFaRateLimiter,
                $responseFactory,
                'login:2fa',
                $twoFaRateLimit['limit'],
                $twoFaRateLimit['window'],
                $config->trustCloudflareIp,
                // Pseudonymized (OWASP/BSI audit remediation issue 11) —
                // never the plaintext IP in the audit log.
                static function (string $ip) use ($audit, $auditIpHasher): void {
                    $audit->log('login_2fa.rate_limited', ['ip_hmac' => $auditIpHasher->hash($ip)]);
                },
            ))
            ->add(RateLimitMiddleware::perAction(
                $twoFaRateLimiter,
                $responseFactory,
                'login:2fa:token',
                $twoFaTokenRateLimit['limit'],
                $twoFaTokenRateLimit['window'],
                static function (ServerRequestInterface $r) use ($vault): ?string {
                    $parsed = $r->getParsedBody();
                    $token  = is_array($parsed) ? (string) ($parsed['pending_token'] ?? '') : '';
                    return $token !== '' ? $vault->hash($token) : null;
                },
                static function (string $identity) use ($audit, $reporter): void {
                    $audit->log('login_2fa.rate_limited', ['token_hash' => $identity]);
                    // Sentry mirror (OWASP/BSI audit remediation issue 12):
                    // sustained failed 2FA attempts against one pending login.
                    $reporter->reportSecurityEvent('login_2fa.rate_limited', 'warning', ['token_hash' => $identity]);
                },
            ));

        // POST /password/reset/request — "forgot password" step A (AuthZ: anon).
        // Response is ALWAYS identical (anti-enumeration, like POST /login) —
        // see PasswordResetRequestAction class doc. Same dual-bucket pattern
        // (email + IP) as magiclink:email/magiclink:ip.
        $resetEmailRateLimit = $config->rateLimit('password:reset:email');
        $resetIpRateLimit    = $config->rateLimit('password:reset:ip');
        $passwordResetMailer = new PasswordResetMailer($tokenRepo, $vault, $mailer, $smtpResolver, $config);
        $app->post(
            '/password/reset/request',
            new PasswordResetRequestAction($userRepo, $hasher, $vault, $passwordResetMailer, $audit, $auditIpHasher, $config->trustCloudflareIp),
        )
            ->setName(CoreRoute::PASSWORD_RESET_REQUEST)
            ->add(AuthZMiddleware::anon($responseFactory))
            ->add(RateLimitMiddleware::perAction(
                new RateLimiter($conn),
                $responseFactory,
                'password:reset:email',
                $resetEmailRateLimit['limit'],
                $resetEmailRateLimit['window'],
                static function (ServerRequestInterface $r) use ($hasher): ?string {
                    $parsed = $r->getParsedBody();
                    $email  = is_array($parsed) ? trim((string) ($parsed['email'] ?? '')) : '';
                    return $email !== '' ? $hasher->hash($email) : null;
                },
                static function (string $emailHmac) use ($audit): void {
                    $audit->log('password_reset.rate_limited', ['email_hmac' => $emailHmac]);
                },
            ))
            ->add(RateLimitMiddleware::perAction(
                new RateLimiter($conn),
                $responseFactory,
                'password:reset:ip',
                $resetIpRateLimit['limit'],
                $resetIpRateLimit['window'],
                static fn (ServerRequestInterface $r): ?string => ClientIp::resolve($r, $config->trustCloudflareIp),
                static function (string $ip) use ($audit, $auditIpHasher): void {
                    $audit->log('password_reset.rate_limited', ['ip_hmac' => $auditIpHasher->hash($ip)]);
                },
            ));

        // POST /password/reset/confirm — "forgot password" step B (AuthZ: anon —
        // the single-use reset token itself is the capability, analog /login/verify).
        $app->post('/password/reset/confirm', new PasswordResetConfirmAction($userRepo, $tokenRepo, $vault, $audit, $auditIpHasher, $passwordPolicy, $config->trustCloudflareIp, $reporter))
            ->add(AuthZMiddleware::anon($responseFactory));

        // GET /login/oauth/{provider}/start + GET /login/oauth/{provider}/callback
        // — OAuth2 login (Google/GitHub), additive to magic-link/password/TOTP,
        // landing on the SAME LoginSessionIssuer. AuthZ: anon. Both fail-secure
        // 404 for an unknown or unconfigured provider (OAuthStartAction/
        // OAuthCallbackAction check Config::oauthProviderConfigured()
        // themselves) — a self-host installation with no oauth_providers
        // config simply never exposes these routes' functionality, same
        // posture as every other opt-in config-gated feature here.
        $oauthStateRepo    = new OAuthStateRepository($conn);
        $oauthIdentityRepo = new OAuthIdentityRepository($conn);

        $app->get('/login/oauth/{provider}/start', new OAuthStartAction($config, $oauthStateRepo, $vault, $audit))
            ->add(AuthZMiddleware::anon($responseFactory));

        // Rate limit mirrors login:password/login:2fa (per-IP, config-driven,
        // fails closed to Config::UNCONFIGURED_ACTION_LIMIT if unset) — a
        // provider-error/replay loop against this endpoint is otherwise
        // unthrottled request volume just like any other login route.
        $oauthCallbackRateLimit  = $config->rateLimit('login:oauth');
        $oauthCallbackRateLimiter = new RateLimiter($conn);
        $app->get(
            '/login/oauth/{provider}/callback',
            new OAuthCallbackAction($config, $oauthStateRepo, $oauthIdentityRepo, $userRepo, $tokenRepo, $vault, $hasher, $oauthHttp, $loginSessionIssuer, $audit, $conn),
        )
            ->add(AuthZMiddleware::anon($responseFactory))
            ->add(RateLimitMiddleware::perIp(
                $oauthCallbackRateLimiter,
                $responseFactory,
                'login:oauth',
                $oauthCallbackRateLimit['limit'],
                $oauthCallbackRateLimit['window'],
                $config->trustCloudflareIp,
            ));
    }

    /**
     * Magic-link login mail (shared with bin/send-mail-previews.php).
     *
     * @return array{subject: string, text: string, html: string, image: \Votepit\Mail\InlineImage}
     */
    public static function loginMail(string $link, bool $confirmsProductNews = false): array
    {
        // Signup with the product-news checkbox ticked: exactly one extra sentence
        // (double opt-in, see the marketing-consent planning notes). Without the
        // checkbox the mail stays byte-identical to the plain login link.
        $paragraphs = ['Hello,', 'here is your login link:'];
        if ($confirmsProductNews) {
            $paragraphs[] = 'Clicking this link also confirms your subscription to Votepit product news and offers by e-mail, which you can withdraw at any time.';
        }

        return ['subject' => 'Your Votepit login link', ...MailTemplate::render(
            'Your login link',
            $paragraphs,
            $link,
            'Log in now',
            ['The link is valid for 15 minutes.', 'Please do not share it.'],
        )];
    }

    /**
     * Scheduled-deletion reminder (sent piggy-back on a login request).
     *
     * @return array{subject: string, text: string, html: string, image: \Votepit\Mail\InlineImage}
     */
    public static function deletionReminderMail(\DateTimeImmutable $deadline): array
    {
        return ['subject' => 'Your Votepit account will be deleted soon', ...MailTemplate::render(
            'Your account will be deleted soon',
            [
                'Hello,',
                "deletion has been scheduled for your account. Your account and all "
                . "associated boards, ideas and comments will be permanently deleted on "
                . "{$deadline->format('Y-m-d')}.",
                'Please back up your data before then (export under "Account"). '
                . 'As long as the grace period is running, you can also cancel the '
                . 'deletion there.',
            ],
            null,
            null,
            ['Please do not share it.'],
        )];
    }

    /**
     * Board-operator-compliance reminder: boards frozen for missing legal-operator data.
     *
     * @return array{subject: string, text: string, html: string, image: \Votepit\Mail\InlineImage}
     */
    public static function complianceReminderMail(int $frozenBoardCount): array
    {
        return ['subject' => 'Votepit: one or more of your boards are frozen', ...MailTemplate::render(
            'One or more of your boards are frozen',
            [
                'Hello,',
                sprintf(
                    '%d of your public boards have been frozen because required legal-operator '
                    . 'information is missing or has not been confirmed.',
                    $frozenBoardCount,
                ),
                'Set your legal operator name under "Account" and confirm the board-operator '
                . 'responsibility notice on each affected board to unfreeze them again.',
            ],
            null,
            null,
            ['Please do not share it.'],
        )];
    }
}
