<?php

declare(strict_types=1);

namespace Votepit\Mail;

use Doctrine\DBAL\Exception as DbalException;
use Votepit\Persistence\BoardSmtpSettingsRepository;
use Votepit\Persistence\SmtpSettingsRepository;
use Votepit\Security\EncryptionService;
use Votepit\Security\SmtpHostPolicy;
use Votepit\SmtpConfig;

/**
 * Resolves the SMTP configuration to use.
 *
 * Precedence: board settings → global default (app_settings) → config/config.php.
 *
 * Security review — DNS-rebinding TOCTOU: board/global SMTP relays are only
 * checked against SmtpHostPolicy in BoardSmtpAction::save(), but afterwards
 * are potentially reused unchanged for days/months (every send reloads them
 * here). A tenant/admin who initially configured a public hostname could
 * later repoint its DNS record to an internal target (rebinding) and thereby
 * direct every subsequent send at that target, without ever going through
 * save validation again. `$configFallback` (config/config.php, maintained
 * statically by the operator) is unaffected by this and stays unchecked.
 * Fail-secure: a now-invalid target throws instead of silently falling back.
 */
final readonly class SmtpConfigResolver
{
    public function __construct(
        private SmtpSettingsRepository $globalRepo,
        private BoardSmtpSettingsRepository $boardRepo,
        private EncryptionService $enc,
        private SmtpConfig $configFallback,
        private SmtpHostPolicy $hostPolicy = new SmtpHostPolicy(false),
    ) {}

    /**
     * Resolves the SMTP config. boardId=null → global/fallback only.
     *
     * @throws DbalException
     * @throws \Votepit\ConfigException if a persisted (board/global) relay
     *         target no longer satisfies the host policy
     */
    public function resolve(?int $boardId): SmtpConfig
    {
        if ($boardId !== null) {
            $cfg = $this->boardRepo->findAsSmtpConfig($boardId, $this->enc);
            if ($cfg instanceof SmtpConfig) {
                return $this->assertStillAllowed($cfg);
            }
        }

        $global = $this->globalRepo->findAsSmtpConfig($this->enc);
        return $global instanceof SmtpConfig ? $this->assertStillAllowed($global) : $this->configFallback;
    }

    private function assertStillAllowed(SmtpConfig $cfg): SmtpConfig
    {
        $reason = $this->hostPolicy->rejectionReason($cfg->host);
        if ($reason !== null) {
            throw new \Votepit\ConfigException("SMTP relay target is no longer allowed: {$reason}");
        }
        return $cfg;
    }
}
