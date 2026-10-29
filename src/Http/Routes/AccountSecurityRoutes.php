<?php

declare(strict_types=1);

namespace Votepit\Http\Routes;

use Slim\App;
use Slim\Psr7\Factory\ResponseFactory;
use Votepit\Http\Action\AccountPasswordResetAction;
use Votepit\Http\Action\SetPasswordAction;
use Votepit\Http\Action\TotpAction;
use Votepit\Http\Middleware\AuthZMiddleware;
use Votepit\Logging\AuditLogger;
use Votepit\Logging\AuthzDenialLogger;
use Votepit\Persistence\TotpBackupCodeRepository;
use Votepit\Persistence\UserRepository;
use Votepit\Security\EncryptionService;
use Votepit\Security\IdentityHasher;
use Votepit\Security\Password\PasswordPolicy;
use Votepit\Security\PasswordResetMailer;
use Votepit\Security\SessionService;
use Votepit\Security\Totp;
use Votepit\Security\TotpSetupToken;

/**
 * Logged-in self-service credential management: set password,
 * password-reset link, TOTP setup/confirm/disable/backup codes.
 */
final class AccountSecurityRoutes
{
    /**
     * Account password/TOTP self-service (AuthZ: user).
     *
     * @param App<null> $app
     */
    public static function register(
        App $app,
        ResponseFactory $responseFactory,
        AuditLogger $audit,
        AuthzDenialLogger $authzDenialLogger,
        SessionService $sessions,
        UserRepository $userRepo,
        IdentityHasher $hasher,
        Totp $totp,
        TotpSetupToken $totpSetupToken,
        EncryptionService $totpEncryption,
        TotpBackupCodeRepository $totpBackupCodeRepo,
        PasswordResetMailer $passwordResetMailer,
        PasswordPolicy $passwordPolicy,
    ): void {
        // Profile settings (AuthZ: user) — password + TOTP 2FA are self-
        // configurable by EVERY logged-in user, no special role is required
        // (CLAUDE.md scope note — primarily intended for administrative
        // accounts, but technically available to everyone).
        $app->post('/account/password', new SetPasswordAction($userRepo, $audit, $sessions, $passwordPolicy))
            ->add(AuthZMiddleware::user($responseFactory, $authzDenialLogger));

        // POST /account/password-reset — logged-in self-service "send me a
        // reset link" (AuthZ: user). See AccountPasswordResetAction class doc
        // for why the address must be re-typed (ADR 0002: no plaintext email
        // is ever stored, not even for the caller's own account).
        $app->post('/account/password-reset', new AccountPasswordResetAction($userRepo, $hasher, $passwordResetMailer, $audit))
            ->add(AuthZMiddleware::user($responseFactory, $authzDenialLogger));

        $totpAction = new TotpAction($userRepo, $totpBackupCodeRepo, $totp, $totpSetupToken, $totpEncryption, $audit, $sessions);
        $app->post('/account/totp/setup', $totpAction->setup(...))
            ->add(AuthZMiddleware::user($responseFactory, $authzDenialLogger));
        $app->post('/account/totp/confirm', $totpAction->confirm(...))
            ->add(AuthZMiddleware::user($responseFactory, $authzDenialLogger));
        $app->post('/account/totp/disable', $totpAction->disable(...))
            ->add(AuthZMiddleware::user($responseFactory, $authzDenialLogger));
        $app->post('/account/totp/backup-codes/regenerate', $totpAction->regenerateBackupCodes(...))
            ->add(AuthZMiddleware::user($responseFactory, $authzDenialLogger));
    }
}
