<?php

declare(strict_types=1);

namespace Votepit\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;

/**
 * User-Persistenz (Sprint 2, arch.md §2 — Persistence-Layer).
 *
 * Prepared-Statements-only via DBAL. Kein Query-String-Concat.
 * Board-Scoping gilt hier nicht (users sind board-global).
 * E-Mail wird IMMER lower-cased gespeichert (strtolower vor Übergabe).
 */
final readonly class UserRepository
{
    public function __construct(private Connection $conn) {}

    /**
     * Sucht einen User by E-Mail (Exact-Match, case-sensitive auf UNIQUE-Index).
     *
     * @return array<string, mixed>|null
     * @throws DbalException
     */
    public function findByEmail(string $email): ?array
    {
        $row = $this->conn->fetchAssociative(
            'SELECT id, email, is_admin, is_blocked, verified_at, created_at FROM users WHERE email = :email',
            ['email' => $email],
        );

        return $row === false ? null : $row;
    }

    /**
     * Sucht einen User by ID. Liefert token_version (für die Session-Payload).
     *
     * @return array<string, mixed>|null
     * @throws DbalException
     */
    public function findById(int $id): ?array
    {
        $row = $this->conn->fetchAssociative(
            'SELECT id, email, is_admin, is_blocked, token_version, verified_at, created_at
             FROM users WHERE id = :id',
            ['id' => $id],
        );

        return $row === false ? null : $row;
    }

    /**
     * Setzt verified_at = jetzt, NUR falls noch NULL (idempotent, kein Überschreiben).
     *
     * @throws DbalException
     */
    public function markVerified(int $id): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $this->conn->executeStatement(
            'UPDATE users SET verified_at = :now WHERE id = :id AND verified_at IS NULL',
            ['now' => $now, 'id' => $id],
        );
    }

    /**
     * Setzt is_admin = 1 (idempotent). Aufrufer entscheidet via Config::isAdminEmail.
     * Entfernen aus der Allowlist entzieht Admin NICHT (kein stilles Downgrade).
     *
     * @throws DbalException
     */
    public function promoteAdmin(int $id): void
    {
        $this->conn->executeStatement(
            'UPDATE users SET is_admin = 1 WHERE id = :id',
            ['id' => $id],
        );
    }

    /**
     * Legt einen neuen User mit verifizierter E-Mail an.
     * Wirft DbalException bei Unique-Violation (race condition → außen fangen).
     *
     * @return array<string, mixed>
     * @throws DbalException
     */
    public function create(string $email): array
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $this->conn->executeStatement(
            'INSERT INTO users (email, is_admin, is_blocked, verified_at, created_at)
             VALUES (:email, 0, 0, NULL, :created_at)',
            ['email' => $email, 'created_at' => $now],
        );

        $id = (int) $this->conn->lastInsertId();

        return [
            'id'          => $id,
            'email'       => $email,
            'is_admin'    => 0,
            'is_blocked'  => 0,
            'verified_at' => null,
            'created_at'  => $now,
        ];
    }
}
