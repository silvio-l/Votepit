<?php

declare(strict_types=1);

namespace Votepit\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;

/**
 * Login-Token-Persistenz (Sprint 2, arch.md §4 — TokenVault-Persistenz).
 *
 * Prepared-Statements-only. Speichert NUR den SHA-256-Hash (niemals den Klartext).
 * Löscht offene (unused) Tokens desselben Users vor jedem neuen Insert (kein Anhäufen).
 */
final readonly class LoginTokenRepository
{
    public function __construct(private Connection $conn) {}

    /**
     * Löscht alle offenen (used_at IS NULL) Login-Tokens des Users.
     * Best-effort: wird vor dem neuen Insert aufgerufen, um Token-Anhäufen zu
     * verhindern. Ein Fehler hier unterbricht den Fluss (Caller fängt).
     *
     * @throws DbalException
     */
    public function deleteOpenForUser(int $userId): void
    {
        $this->conn->executeStatement(
            'DELETE FROM login_tokens WHERE user_id = :user_id AND used_at IS NULL',
            ['user_id' => $userId],
        );
    }

    /**
     * Speichert einen neuen Login-Token-Datensatz (Hash, purpose='login').
     * NUR den Hash übergeben — niemals den Klartext-Token.
     *
     * @throws DbalException
     */
    public function insert(int $userId, string $tokenHash, string $expiresAt): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $this->conn->executeStatement(
            'INSERT INTO login_tokens (user_id, token_hash, purpose, expires_at, used_at, created_at)
             VALUES (:user_id, :token_hash, :purpose, :expires_at, NULL, :created_at)',
            [
                'user_id'    => $userId,
                'token_hash' => $tokenHash,
                'purpose'    => 'login',
                'expires_at' => $expiresAt,
                'created_at' => $now,
            ],
        );
    }
}
