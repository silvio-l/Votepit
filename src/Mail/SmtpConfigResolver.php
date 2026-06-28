<?php

declare(strict_types=1);

namespace Votepit\Mail;

use Doctrine\DBAL\Exception as DbalException;
use Votepit\Persistence\BoardSmtpSettingsRepository;
use Votepit\Persistence\SmtpSettingsRepository;
use Votepit\Security\EncryptionService;
use Votepit\SmtpConfig;

/**
 * Löst die zu nutzende SMTP-Konfiguration auf.
 *
 * Präzedenz: Board-Settings → Global-Default (app_settings) → config/config.php.
 */
final readonly class SmtpConfigResolver
{
    public function __construct(
        private SmtpSettingsRepository $globalRepo,
        private BoardSmtpSettingsRepository $boardRepo,
        private EncryptionService $enc,
        private SmtpConfig $configFallback,
    ) {}

    /**
     * Löst SMTP-Config auf. boardId=null → nur Global/Fallback.
     *
     * @throws DbalException
     */
    public function resolve(?int $boardId): SmtpConfig
    {
        if ($boardId !== null) {
            $cfg = $this->boardRepo->findAsSmtpConfig($boardId, $this->enc);
            if ($cfg instanceof SmtpConfig) {
                return $cfg;
            }
        }

        return $this->globalRepo->findAsSmtpConfig($this->enc) ?? $this->configFallback;
    }
}
