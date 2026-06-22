<?php

declare(strict_types=1);

namespace Votepit\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;

/**
 * Board-Persistenz (Sprint 2, arch.md §2 — Persistence-Layer).
 *
 * Prepared-Statements-only via DBAL. Kein Query-String-Concat. Board-scoped:
 * jeder Zugriff trägt `WHERE slug = :slug` bzw. `WHERE id = :id` — kein
 * Cross-Board-Leak möglich.
 */
final readonly class BoardRepository
{
    public function __construct(private Connection $conn) {}

    /**
     * Findet ein Board anhand seines slug (UNIQUE). Liefert auch die
     * Branding-Spalten (Issue 08).
     *
     * @return array<string, mixed>|null
     * @throws DbalException
     */
    public function findBySlug(string $slug): ?array
    {
        $row = $this->conn->fetchAssociative(
            'SELECT id, slug, name, accent_color, primary_color, secondary_color,
                    logo_url, intro, is_default, created_at
             FROM boards WHERE slug = :slug',
            ['slug' => $slug],
        );

        return $row === false ? null : $row;
    }

    /**
     * Setzt das Branding eines Boards (board-scoped via id). Werte MÜSSEN vom
     * Aufrufer bereits validiert/sanitisiert sein (BrandingValidator); null
     * setzt die jeweilige Spalte zurück → Default-Theme.
     *
     * @throws DbalException
     */
    public function updateBranding(
        int $id,
        ?string $primaryColor,
        ?string $secondaryColor,
        ?string $logoUrl,
    ): void {
        $this->conn->executeStatement(
            'UPDATE boards
             SET primary_color = :primary, secondary_color = :secondary, logo_url = :logo
             WHERE id = :id',
            [
                'primary'   => $primaryColor,
                'secondary' => $secondaryColor,
                'logo'      => $logoUrl,
                'id'        => $id,
            ],
        );
    }
}
