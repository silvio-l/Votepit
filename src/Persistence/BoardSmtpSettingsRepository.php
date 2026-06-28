<?php

declare(strict_types=1);

namespace Votepit\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Votepit\Security\EncryptionService;
use Votepit\SmtpConfig;

/**
 * Persistenz für board-spezifische SMTP-Konfiguration (board_smtp_settings).
 *
 * Prepared-Statements-only via DBAL. Passwort verschlüsselt at rest
 * (sodium_crypto_secretbox via EncryptionService). GET liefert Passwort
 * NIEMALS im Klartext.
 */
final readonly class BoardSmtpSettingsRepository
{
    public function __construct(private Connection $conn) {}

    /**
     * Liest Board-SMTP-Einstellungen. Null = nicht konfiguriert (Fallback auf Global).
     *
     * @return array<string, mixed>|null
     * @throws DbalException
     */
    public function find(int $boardId): ?array
    {
        $row = $this->conn->fetchAssociative(
            'SELECT host, port, user, pass, encryption, from_email, from_name
               FROM board_smtp_settings WHERE board_id = :board_id',
            ['board_id' => $boardId],
        );

        if ($row === false || (string) ($row['host'] ?? '') === '') {
            return null;
        }

        return $row;
    }

    /**
     * Baut SmtpConfig aus Board-Einstellungen. Null wenn nicht konfiguriert.
     *
     * @throws DbalException
     */
    public function findAsSmtpConfig(int $boardId, EncryptionService $enc): ?SmtpConfig
    {
        $row = $this->find($boardId);
        if ($row === null) {
            return null;
        }

        $encPw = is_string($row['pass'] ?? null) ? $row['pass'] : '';
        $pass  = $encPw !== '' ? ($enc->decrypt($encPw) ?? '') : '';

        try {
            return SmtpConfig::fromArray([
                'host'       => (string) ($row['host'] ?? ''),
                'port'       => (int) ($row['port'] ?? 587),
                'user'       => (string) ($row['user'] ?? ''),
                'pass'       => $pass,
                'encryption' => (string) ($row['encryption'] ?? 'tls'),
                'from_email' => (string) ($row['from_email'] ?? ''),
                'from_name'  => (string) ($row['from_name'] ?? 'Votepit'),
            ]);
        } catch (\Votepit\ConfigException) {
            return null;
        }
    }

    /**
     * UPSERT: speichert Board-SMTP. Passwort nur aktualisieren wenn $encryptedPass !== null.
     *
     * @throws DbalException
     */
    public function save(
        int $boardId,
        string $host,
        int $port,
        string $user,
        string $encryption,
        string $fromEmail,
        string $fromName,
        ?string $encryptedPass,
    ): void {
        $existing = $this->conn->fetchOne(
            'SELECT id FROM board_smtp_settings WHERE board_id = :board_id',
            ['board_id' => $boardId],
        );

        if ($existing === false) {
            // INSERT
            $data = [
                'board_id'   => $boardId,
                'host'       => $host,
                'port'       => $port,
                'user'       => $user,
                'encryption' => $encryption,
                'from_email' => $fromEmail,
                'from_name'  => $fromName,
            ];
            if ($encryptedPass !== null) {
                $data['pass'] = $encryptedPass;
            }
            $this->conn->insert('board_smtp_settings', $data);
        } else {
            // UPDATE
            $data = [
                'host'       => $host,
                'port'       => $port,
                'user'       => $user,
                'encryption' => $encryption,
                'from_email' => $fromEmail,
                'from_name'  => $fromName,
            ];
            if ($encryptedPass !== null) {
                $data['pass'] = $encryptedPass;
            }
            $this->conn->update('board_smtp_settings', $data, ['board_id' => $boardId]);
        }
    }

    /**
     * Löscht Board-SMTP (zurücksetzen auf globalen Default).
     *
     * @throws DbalException
     */
    public function delete(int $boardId): void
    {
        $this->conn->delete('board_smtp_settings', ['board_id' => $boardId]);
    }
}
