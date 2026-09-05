<?php

declare(strict_types=1);

namespace Votepit\Migrations;

use Doctrine\DBAL\Connection;
use Votepit\Config;
use Votepit\Security\IdentityHasher;

/**
 * 0005_backfill_email_hmac — Sprint 1.2b (ADR 0002): berechnet email_hmac
 * (HMAC-SHA256 der normalisierten E-Mail, serverKey aus Config::$identityServerKey,
 * siehe IdentityHasher) für alle bestehenden users-Zeilen.
 *
 * ConfigAwareMigration, weil der HMAC-Key DB-extern in der Config liegt (ADR
 * 0002 — genau das ist der Punkt: ein reiner DB-Leak darf keinen Rückschluss
 * auf die Klartext-E-Mail erlauben).
 *
 * Batched (BATCH_SIZE Zeilen/Runde) statt einer Monster-UPDATE-Transaktion —
 * bei dieser Tabellengröße nicht kritisch, aber gute Praxis für künftig
 * größere Installationen. Idempotent: verarbeitet nur Zeilen mit
 * email_hmac IS NULL, ein zweiter Lauf ist ein No-Op.
 */
final class BackfillEmailHmacMigration implements ConfigAwareMigration
{
    private const BATCH_SIZE = 500;

    public function version(): string
    {
        return '0005_backfill_email_hmac';
    }

    /**
     * Reiner Vertrags-Erfüller für Migration::up() — diese Migration braucht
     * zwingend den identity_server_key aus der Config und darf ohne sie NICHT
     * fail-open weiterlaufen. MigrationRunner ruft up() nur, wenn keine Config
     * übergeben wurde; das ist hier ein Konfigurationsfehler, kein Normalfall.
     */
    public function up(Connection $conn): void
    {
        throw new \RuntimeException(
            '0005_backfill_email_hmac ist ConfigAwareMigration und benötigt identity_server_key — upWithConfig() nutzen.',
        );
    }

    public function upWithConfig(Connection $conn, Config $config): void
    {
        $hasher = new IdentityHasher($config->identityServerKey);

        while (true) {
            /** @var list<array{id: int|string, email: string}> $rows */
            $rows = $conn->fetchAllAssociative(
                'SELECT id, email FROM users WHERE email_hmac IS NULL ORDER BY id LIMIT :batch_size',
                ['batch_size' => self::BATCH_SIZE],
            );

            if ($rows === []) {
                break;
            }

            foreach ($rows as $row) {
                $conn->executeStatement(
                    'UPDATE users SET email_hmac = :email_hmac WHERE id = :id',
                    [
                        'email_hmac' => $hasher->hash($row['email']),
                        'id'         => $row['id'],
                    ],
                );
            }
        }
    }
}

return new BackfillEmailHmacMigration();
