<?php

declare(strict_types=1);

namespace Votepit\Tests\Persistence;

use Votepit\Persistence\UserRepository;
use Votepit\Security\IdentityHasher;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Persistence seam for UserRepository (ADR 0002).
 *
 * Since this wave, UserRepository knows ONLY email_hmac — never plaintext
 * email. These tests deliberately seed directly via DBAL (not via
 * IntegrationTestCase::insertUser(), which additionally maintains the
 * legacy email column that AppFactory.php — next wave — still needs), to
 * verify the repository API in isolation from this transitional state.
 */
final class UserRepositoryTest extends IntegrationTestCase
{
    private UserRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new UserRepository($this->conn);
    }

    private function hasher(): IdentityHasher
    {
        return new IdentityHasher(self::identityServerKey());
    }

    public function test_create_persists_only_the_hmac_never_plaintext(): void
    {
        $hmac = $this->hasher()->hash('fresh@example.com');

        $user = $this->repo->create($hmac);

        self::assertSame($hmac, $user['email_hmac']);
        self::assertArrayNotHasKey('email', $user);

        $row = $this->conn->fetchAssociative('SELECT * FROM users WHERE id = :id', ['id' => $user['id']]);
        self::assertIsArray($row);
        self::assertSame($hmac, $row['email_hmac']);
    }

    public function test_find_by_email_hmac_returns_the_matching_user(): void
    {
        $hmac = $this->hasher()->hash('lookup@example.com');
        $created = $this->repo->create($hmac);

        $found = $this->repo->findByEmailHmac($hmac);

        self::assertIsArray($found);
        self::assertSame($created['id'], $found['id']);
        self::assertSame($hmac, $found['email_hmac']);
    }

    public function test_find_by_email_hmac_returns_null_for_unknown_hmac(): void
    {
        self::assertNull($this->repo->findByEmailHmac($this->hasher()->hash('nobody@example.com')));
    }

    public function test_find_by_id_returns_email_hmac_and_token_version(): void
    {
        $hmac = $this->hasher()->hash('byid@example.com');
        $created = $this->repo->create($hmac);

        $found = $this->repo->findById((int) $created['id']);

        self::assertIsArray($found);
        self::assertSame($hmac, $found['email_hmac']);
        self::assertSame(0, (int) $found['token_version']);
    }

    public function test_mark_verified_sets_verified_at_only_once(): void
    {
        $created = $this->repo->create($this->hasher()->hash('verify@example.com'));
        $id      = (int) $created['id'];

        $this->repo->markVerified($id);
        $first = $this->conn->fetchOne('SELECT verified_at FROM users WHERE id = :id', ['id' => $id]);
        self::assertNotNull($first);

        // A second call does not overwrite an already-set verified_at.
        $this->conn->executeStatement('UPDATE users SET verified_at = :v WHERE id = :id', ['v' => '2020-01-01 00:00:00', 'id' => $id]);
        $this->repo->markVerified($id);
        $second = $this->conn->fetchOne('SELECT verified_at FROM users WHERE id = :id', ['id' => $id]);
        self::assertSame('2020-01-01 00:00:00', $second);
    }

    public function test_promote_admin_sets_is_admin(): void
    {
        $created = $this->repo->create($this->hasher()->hash('admin@example.com'));
        $id      = (int) $created['id'];

        $this->repo->promoteAdmin($id);

        self::assertSame(1, (int) $this->conn->fetchOne('SELECT is_admin FROM users WHERE id = :id', ['id' => $id]));
    }

    public function test_bump_token_version_increments(): void
    {
        $created = $this->repo->create($this->hasher()->hash('bump@example.com'));
        $id      = (int) $created['id'];

        $this->repo->bumpTokenVersion($id);
        $this->repo->bumpTokenVersion($id);

        self::assertSame(2, (int) $this->conn->fetchOne('SELECT token_version FROM users WHERE id = :id', ['id' => $id]));
    }

    public function test_create_throws_on_duplicate_email_hmac(): void
    {
        $hmac = $this->hasher()->hash('dup@example.com');
        $this->repo->create($hmac);

        $this->expectException(\Doctrine\DBAL\Exception::class);
        $this->repo->create($hmac);
    }
}
