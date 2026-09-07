<?php

declare(strict_types=1);

namespace Votepit\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Votepit\Security\EncryptionService;
use Votepit\SmtpConfig;

/**
 * Persistence for board-specific SMTP configuration (board_smtp_settings).
 *
 * Prepared-statements-only via DBAL. Password encrypted at rest
 * (sodium_crypto_secretbox via EncryptionService). GET NEVER returns the
 * password in plaintext.
 */
final readonly class BoardSmtpSettingsRepository
{
    public function __construct(private Connection $conn) {}

    /**
     * Reads board SMTP settings. Null = not configured (falls back to global).
     *
     * @return array<string, mixed>|null
     * @throws DbalException
     */
    public function find(int $boardId): ?array
    {
        $row = $this->conn->fetchAssociative(
            'SELECT host, port, user, pass, encryption, from_email, from_name, verify_peer
               FROM board_smtp_settings WHERE board_id = :board_id',
            ['board_id' => $boardId],
        );

        if ($row === false || (string) ($row['host'] ?? '') === '') {
            return null;
        }

        return $row;
    }

    /**
     * Builds an SmtpConfig from board settings. Null if not configured.
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
                'host'        => (string) ($row['host'] ?? ''),
                'port'        => (int) ($row['port'] ?? 587),
                'user'        => (string) ($row['user'] ?? ''),
                'pass'        => $pass,
                'encryption'  => (string) ($row['encryption'] ?? 'tls'),
                'from_email'  => (string) ($row['from_email'] ?? ''),
                'from_name'   => (string) ($row['from_name'] ?? 'Votepit'),
                'verify_peer' => (bool) ($row['verify_peer'] ?? true),
            ]);
        } catch (\Votepit\ConfigException) {
            return null;
        }
    }

    /**
     * UPSERT: saves board SMTP. Only update password if $encryptedPass !== null.
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
        bool $verifyPeer = true,
    ): void {
        $existing = $this->conn->fetchOne(
            'SELECT id FROM board_smtp_settings WHERE board_id = :board_id',
            ['board_id' => $boardId],
        );

        if ($existing === false) {
            // INSERT
            $data = [
                'board_id'    => $boardId,
                'host'        => $host,
                'port'        => $port,
                'user'        => $user,
                'encryption'  => $encryption,
                'from_email'  => $fromEmail,
                'from_name'   => $fromName,
                'verify_peer' => $verifyPeer ? 1 : 0,
            ];
            if ($encryptedPass !== null) {
                $data['pass'] = $encryptedPass;
            }
            $this->conn->insert('board_smtp_settings', $data);
        } else {
            // UPDATE
            $data = [
                'host'        => $host,
                'port'        => $port,
                'user'        => $user,
                'encryption'  => $encryption,
                'from_email'  => $fromEmail,
                'from_name'   => $fromName,
                'verify_peer' => $verifyPeer ? 1 : 0,
            ];
            if ($encryptedPass !== null) {
                $data['pass'] = $encryptedPass;
            }
            $this->conn->update('board_smtp_settings', $data, ['board_id' => $boardId]);
        }
    }

    /**
     * Deletes board SMTP (resets to the global default).
     *
     * @throws DbalException
     */
    public function delete(int $boardId): void
    {
        $this->conn->delete('board_smtp_settings', ['board_id' => $boardId]);
    }

    /**
     * Lists board SMTP METADATA of an account, across all boards
     * (customer self-export). Account-scoped via a JOIN on
     * boards (board_smtp_settings itself carries no account_id column).
     * NEVER return `pass` — neither encrypted nor decrypted:
     * an export must never hand out the encrypted-at-rest blob (that would
     * effectively be the same confidentiality guarantee as a plaintext leak
     * if app_key were ever compromised), and must especially never call
     * decrypt(). Only operational metadata (host/port/user/encryption/from/
     * verify-peer/timestamps).
     *
     * @return list<array<string, mixed>>
     * @throws DbalException
     */
    public function listMetadataForAccount(int $accountId): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->conn->fetchAllAssociative(
            'SELECT s.board_id, s.host, s.port, s.user, s.encryption, s.from_email, s.from_name,
                    s.verify_peer, s.created_at, s.updated_at
             FROM board_smtp_settings s
             INNER JOIN boards b ON b.id = s.board_id
             WHERE b.account_id = :account_id
             ORDER BY s.board_id ASC',
            ['account_id' => $accountId],
        );

        return $rows;
    }
}
