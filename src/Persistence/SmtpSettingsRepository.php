<?php

declare(strict_types=1);

namespace Votepit\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Votepit\Security\EncryptionService;
use Votepit\SmtpConfig;

/**
 * Persistence for the installation-wide SMTP configuration (app_settings).
 *
 * Prepared-statements-only via DBAL. Password is stored encrypted
 * (sodium_crypto_secretbox via EncryptionService). The GET endpoint
 * NEVER returns the password in plaintext.
 */
final readonly class SmtpSettingsRepository
{
    public function __construct(private Connection $conn) {}

    /**
     * Reads all SMTP settings from app_settings.
     * Returns null if no SMTP host is configured yet.
     *
     * @return array<string, string|null>|null
     * @throws DbalException
     */
    public function find(): ?array
    {
        $rows = $this->conn->fetchAllAssociative(
            "SELECT `key`, value FROM app_settings WHERE `key` LIKE 'smtp.%'",
        );

        if ($rows === []) {
            return null;
        }

        $settings = [];
        foreach ($rows as $row) {
            $settings[(string) $row['key']] = is_string($row['value']) ? $row['value'] : null;
        }

        // No host = not configured.
        if (($settings['smtp.host'] ?? '') === '') {
            return null;
        }

        return $settings;
    }

    /**
     * Builds an SmtpConfig from DB settings. Null if not configured.
     * Decrypts the password using EncryptionService.
     *
     * @throws DbalException
     */
    public function findAsSmtpConfig(EncryptionService $enc): ?SmtpConfig
    {
        $settings = $this->find();
        if ($settings === null) {
            return null;
        }

        $encPw = $settings['smtp.pass'] ?? '';
        $pass  = $encPw !== '' ? ($enc->decrypt($encPw) ?? '') : '';

        try {
            return SmtpConfig::fromArray([
                'host'        => $settings['smtp.host'] ?? '',
                'port'        => (int) ($settings['smtp.port'] ?? 587),
                'user'        => $settings['smtp.user'] ?? '',
                'pass'        => $pass,
                'encryption'  => $settings['smtp.encryption'] ?? 'tls',
                'from_email'  => $settings['smtp.from_email'] ?? '',
                'from_name'   => $settings['smtp.from_name'] ?? 'Votepit',
                'verify_peer' => ($settings['smtp.verify_peer'] ?? '1') !== '0',
            ]);
        } catch (\Votepit\ConfigException) {
            return null; // Invalid from_email in DB → fall back to config.php
        }
    }

    /**
     * Saves SMTP settings (UPSERT). Password is only updated if $encryptedPass !== null.
     *
     * @throws DbalException
     */
    public function save(
        string $host,
        int $port,
        string $user,
        string $encryption,
        string $fromEmail,
        string $fromName,
        ?string $encryptedPass,
        bool $verifyPeer = true,
    ): void {
        $fields = [
            'smtp.host'        => $host,
            'smtp.port'        => (string) $port,
            'smtp.user'        => $user,
            'smtp.encryption'  => $encryption,
            'smtp.from_email'  => $fromEmail,
            'smtp.from_name'   => $fromName,
            'smtp.verify_peer' => $verifyPeer ? '1' : '0',
        ];

        if ($encryptedPass !== null) {
            $fields['smtp.pass'] = $encryptedPass;
        }

        foreach ($fields as $key => $value) {
            $this->upsert($key, $value);
        }
    }

    /** @throws DbalException */
    private function upsert(string $key, string $value): void
    {
        if ($this->conn->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $this->conn->executeStatement(
                "INSERT INTO app_settings (`key`, value) VALUES (:key, :value)
                 ON DUPLICATE KEY UPDATE value = :value2",
                ['key' => $key, 'value' => $value, 'value2' => $value],
            );
        } else {
            // SQLite-compatible (tests).
            $this->conn->executeStatement(
                'INSERT OR REPLACE INTO app_settings ("key", value) VALUES (:key, :value)',
                ['key' => $key, 'value' => $value],
            );
        }
    }
}
