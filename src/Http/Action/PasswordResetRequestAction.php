<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Config;
use Votepit\Logging\AuditLogger;
use Votepit\Mail\Mailer;
use Votepit\Mail\MailTemplate;
use Votepit\Mail\SmtpConfigResolver;
use Votepit\Mail\SymfonyMailerAdapter;
use Votepit\Persistence\LoginTokenRepository;
use Votepit\Persistence\UserRepository;
use Votepit\Security\IdentityHasher;
use Votepit\Security\TokenVault;

/**
 * POST /password/reset/request — step A of "forgot password" (AuthZ: anon).
 * Body: { email }. Response is ALWAYS the identical {ok: true} regardless of
 * whether the address matches an account — same anti-enumeration contract as
 * POST /login (LoginActionTest AC3/4). Unlike POST /login, this action does
 * NOT auto-create an unknown account (a reset only makes sense for an
 * existing account) — so the "always identical work" trick POST /login uses
 * (create-on-demand + always mail) isn't available here; instead the unknown
 * branch runs an equivalent-cost dummy DB round trip + token generation (see
 * NO_MATCH_DUMMY_USER_ID) instead of the real insert+send, matching the
 * DUMMY_HASH approach LoginPasswordAction uses for password_verify(). This
 * closes the CPU-cost gap but NOT a full SMTP-round-trip timing gap (the real
 * branch calls Mailer::send(), the dummy branch does not) — an accepted,
 * documented limitation; see CLAUDE.md fail-secure guardrail discussion in
 * the PR description for this feature.
 *
 * Rate-limited by both email (password:reset:email) and IP
 * (password:reset:ip) — mirrors the magiclink:email/magiclink:ip dual-bucket
 * pattern in AppFactory's POST /login wiring.
 */
final readonly class PasswordResetRequestAction
{
    /**
     * TTL of the reset token: 30 minutes. Shorter than the 7-day invite TTL
     * (a credential-replacement capability is far more sensitive than an
     * invite) but longer than the 15-minute magic-link TTL — resetting a
     * password is a slower, more deliberate user action (open mail client,
     * come back, type + confirm a new password) than following a login link.
     */
    private const TOKEN_TTL_SECONDS = 1800;

    public function __construct(
        private UserRepository $userRepo,
        private IdentityHasher $hasher,
        private LoginTokenRepository $tokenRepo,
        private TokenVault $vault,
        private ?Mailer $mailer,
        private SmtpConfigResolver $smtpResolver,
        private Config $config,
        private AuditLogger $audit,
    ) {}

    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $parsed = $request->getParsedBody();
        $email  = is_array($parsed) ? strtolower(trim((string) ($parsed['email'] ?? ''))) : '';

        // Only run any work at all for syntactically valid addresses — same
        // neutral no-op POST /login uses for malformed input (LoginActionTest
        // AC4). Still returns the same {ok: true}.
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
            $emailHmac = $this->hasher->hash($email);
            $user      = $this->userRepo->findByEmailHmac($emailHmac);

            if (is_array($user)) {
                $this->sendResetMail((int) $user['id'], $email);
                $this->audit->log('password.reset_requested', ['email_hmac' => $emailHmac]);
            } else {
                // Equivalent-cost dummy pass: a DB round trip comparable to
                // the real branch's token insert, plus the same token-crypto
                // call — no mail sent, no login_tokens row written.
                $this->userRepo->findById(0);
                $this->vault->generate();
                $this->audit->log('password.reset_requested', ['email_hmac' => null]);
            }
        }

        $response->getBody()->write((string) json_encode(['ok' => true]));
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }

    private function sendResetMail(int $userId, string $email): void
    {
        // Same "no accumulation" convention as POST /login / InviteAction —
        // deletes ANY open token for this user (any purpose), so a fresh
        // reset request invalidates a stale pending magic-link/2FA/reset
        // token rather than piling up.
        $this->tokenRepo->deleteOpenForUser($userId);

        $pair      = $this->vault->generate();
        $expiresAt = (new \DateTimeImmutable('+' . self::TOKEN_TTL_SECONDS . ' seconds'))
            ->format('Y-m-d H:i:s');
        $this->tokenRepo->insert($userId, $pair['hash'], $expiresAt, 'password_reset');

        // No board context here (global/identity-scoped route, like invite
        // accept) — resolve(null) falls back to the installation-wide SMTP
        // config, same as InviteAction.
        $link      = $this->config->appUrl . '/password/reset/confirm?token=' . $pair['token'];
        $mailToUse = $this->mailer ?? new SymfonyMailerAdapter($this->smtpResolver->resolve(null));

        $resetMail = MailTemplate::render(
            'Reset your password',
            [
                'Hello,',
                'you requested to reset your Votepit password. '
                . 'Click the following link to set a new password:',
            ],
            $link,
            'Set a new password',
            ['The link is valid for 30 minutes.', 'If this was not you, please ignore this email.'],
        );

        $mailToUse->send(
            $email,
            'Reset your Votepit password',
            $resetMail['text'],
            $resetMail['html'],
            $resetMail['image'],
        );
    }
}
