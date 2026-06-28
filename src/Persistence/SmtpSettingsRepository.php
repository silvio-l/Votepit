<?php

declare(strict_types=1);

namespace Votepit\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Votepit\Security\EncryptionService;
use Votepit\SmtpConfig;

/**
 * Persistenz für die installations-weite SMTP-Konfiguration (app_settings).
 *
 * Prepared-Statements-only via DBAL. Passwort wird verschlüsselt gespeichert
 * (sodium_crypto_secretbox via EncryptionService). GET-Endpunkt gibt Passwort
 * NIEMALS im Klartext zurück.
 */
final readonly class SmtpSettingsRepository
{
    public function __construct(private Connection $conn) {}

    /**
     * Liest alle SMTP-Settings aus app_settings.
     * Liefert null wenn noch kein SMTP-Host konfiguriert ist.
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

        // Kein Host = nicht konfiguriert.
        if (($settings['smtp.host'] ?? '') === '') {
            return null;
        }

        return $settings;
    }

    /**
     * Baut SmtpConfig aus DB-Einstellungen. Null wenn nicht konfiguriert.
     * Entschlüsselt das Passwort mit EncryptionService.
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
                'host'       => $settings['smtp.host'] ?? '',
                'port'       => (int) ($settings['smtp.port'] ?? 587),
                'user'       => $settings['smtp.user'] ?? '',
                'pass'       => $pass,
                'encryption' => $settings['smtp.encryption'] ?? 'tls',
                'from_email' => $settings['smtp.from_email'] ?? '',
                'from_name'  => $settings['smtp.from_name'] ?? 'Votepit',
            ]);
        } catch (\Votepit\ConfigException) {
            return null; // Ungültige from_email in DB → Fallback auf config.php
        }
    }

    /**
     * Speichert SMTP-Settings (UPSERT). Passwort wird nur aktualisiert wenn $encryptedPass !== null.
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
    ): void {
        $fields = [
            'smtp.host'       => $host,
            'smtp.port'       => (string) $port,
            'smtp.user'       => $user,
            'smtp.encryption' => $encryption,
            'smtp.from_email' => $fromEmail,
            'smtp.from_name'  => $fromName,
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
            // SQLite-kompatibel (Tests).
            $this->conn->executeStatement(
                'INSERT OR REPLACE INTO app_settings ("key", value) VALUES (:key, :value)',
                ['key' => $key, 'value' => $value],
            );
        }
    }
}
