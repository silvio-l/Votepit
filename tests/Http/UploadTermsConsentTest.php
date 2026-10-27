<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\UploadedFile;
use Votepit\Persistence\UploadTermsConsentRepository;
use Votepit\Security\CsrfService;
use Votepit\Security\IdentityHasher;
use Votepit\Security\SessionService;
use Votepit\Tests\Support\IntegrationTestCase;
use Votepit\Tests\Support\StubExtension;

/**
 * Upload clause consent (AGB 19, migration 0098/0101) over HTTP.
 *
 * Covers: no upload of any kind without the current version (fail-secure),
 * the accept endpoint (owner/admin, idempotent, frozen board, unknown board,
 * anon), version bump asks again, consent of one account never answers for
 * another, the stored row holds only pseudonymous fields after erasure, and the
 * UI language is kept as proof (invalid values stored as NULL).
 */
final class UploadTermsConsentTest extends IntegrationTestCase
{
    private const APP_KEY = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const SLUG    = 'demo';
    private const VERSION = '2026-test-terms';

    /**
     * The app with a configured upload-terms version, supplied the way cloud supplies it.
     *
     * @return App<null>
     */
    private function termsApp(): App
    {
        return $this->createApp(extensions: [StubExtension::fromOptions(['upload_terms_version' => self::VERSION])]);
    }

    private function png(int $w, int $h): string
    {
        if ($w < 1 || $h < 1) {
            throw new \InvalidArgumentException('PNG fixtures need positive dimensions.');
        }
        $image = imagecreatetruecolor($w, $h);
        self::assertNotFalse($image);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 30, 90, 200));
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    private function upload(string $bytes): UploadedFile
    {
        return new UploadedFile((new StreamFactory())->createStream($bytes), 'image.png', 'image/png', strlen($bytes), UPLOAD_ERR_OK);
    }

    private function seedUser(string $email, string $role): int
    {
        $userId = $this->insertUser($email);
        $this->insertAccountMember($this->defaultAccountId(), $userId, $role);

        return $userId;
    }

    /**
     * @param array<string, string> $fields
     * @param array<string, UploadedFile> $files
     */
    private function request(string $method, string $path, ?int $userId, array $fields = [], array $files = []): ServerRequestInterface
    {
        $csrf    = new CsrfService(self::APP_KEY, 3600, false);
        $token   = $csrf->generate();
        $cookies = ['votepit_csrf' => $csrf->sign($token)];
        if ($userId !== null) {
            $cookies['votepit_sess'] = (new SessionService(self::APP_KEY, 3600, false))->sign(['uid' => $userId, 'v' => 0]);
        }
        $request = (new ServerRequestFactory())->createServerRequest($method, $path)
            ->withCookieParams($cookies)
            ->withParsedBody($fields + ['_csrf' => $token]);

        return $files === [] ? $request : $request->withUploadedFiles($files);
    }

    private function acceptPath(): string
    {
        return '/admin/boards/' . self::SLUG . '/assets/terms/accept';
    }

    /** @return array{int, int} [boardId, ownerId] — pro plan, so every image kind is plan-allowed. */
    private function seedOwnerWithBoard(): array
    {
        $boardId = $this->insertBoard(self::SLUG, ['visibility' => 'public']);
        $ownerId = $this->seedUser('owner@example.com', 'owner');
        $this->setAccountPlan($this->defaultAccountId(), 'pro');

        return [$boardId, $ownerId];
    }

    private function uploadRows(int $boardId): int
    {
        return (int) $this->conn->fetchOne('SELECT COUNT(*) FROM board_assets WHERE board_id = ?', [$boardId]);
    }

    private function consentRows(int $accountId): int
    {
        return (int) $this->conn->fetchOne('SELECT COUNT(*) FROM upload_terms_consents WHERE account_id = ?', [$accountId]);
    }

    public function test_upload_of_every_kind_is_refused_without_consent_and_stores_nothing(): void
    {
        [$boardId, $ownerId] = $this->seedOwnerWithBoard();
        $app = $this->termsApp();

        foreach (['cover', 'favicon', 'og', 'logo'] as $kind) {
            $response = $app->handle($this->request('POST', '/admin/boards/' . self::SLUG . '/assets/' . $kind, $ownerId, [], ['image' => $this->upload($this->png(1200, 800))]));

            self::assertSame(403, $response->getStatusCode(), $kind);
            self::assertStringContainsString('upload_terms_required', (string) $response->getBody(), $kind);
        }
        self::assertSame(0, $this->uploadRows($boardId));
    }

    public function test_accept_then_upload_is_allowed_for_every_kind(): void
    {
        [$boardId, $ownerId] = $this->seedOwnerWithBoard();
        $app = $this->termsApp();

        $accept = $app->handle($this->request('POST', $this->acceptPath(), $ownerId));
        self::assertSame(200, $accept->getStatusCode(), (string) $accept->getBody());
        $payload = json_decode((string) $accept->getBody(), true);
        self::assertSame(self::VERSION, $payload['terms_version']);
        self::assertIsString($payload['accepted_at']);

        self::assertSame(200, $app->handle($this->request('POST', '/admin/boards/' . self::SLUG . '/assets/cover', $ownerId, [], ['image' => $this->upload($this->png(1600, 900))]))->getStatusCode());
        self::assertSame(200, $app->handle($this->request('POST', '/admin/boards/' . self::SLUG . '/assets/og', $ownerId, [], ['image' => $this->upload($this->png(1200, 800))]))->getStatusCode());
        self::assertSame(200, $app->handle($this->request('POST', '/admin/boards/' . self::SLUG . '/assets/favicon', $ownerId, [], ['image' => $this->upload($this->png(256, 256))]))->getStatusCode());
        self::assertSame(200, $app->handle($this->request('POST', '/admin/boards/' . self::SLUG . '/assets/logo', $ownerId, [], ['image' => $this->upload($this->png(400, 400))]))->getStatusCode());
        // cover = two renditions (wide + phone), favicon, og and logo = one each.
        self::assertSame(5, $this->uploadRows($boardId));
    }

    public function test_list_reports_the_consent_state_and_version(): void
    {
        [, $ownerId] = $this->seedOwnerWithBoard();
        $app = $this->termsApp();

        $before = json_decode((string) $app->handle($this->request('GET', '/admin/boards/' . self::SLUG . '/assets', $ownerId))->getBody(), true);
        self::assertSame(['version' => self::VERSION, 'accepted' => false], $before['upload_terms']);

        $app->handle($this->request('POST', $this->acceptPath(), $ownerId));

        $after = json_decode((string) $app->handle($this->request('GET', '/admin/boards/' . self::SLUG . '/assets', $ownerId))->getBody(), true);
        self::assertSame(['version' => self::VERSION, 'accepted' => true], $after['upload_terms']);
    }

    public function test_accept_is_idempotent_and_keeps_the_first_row(): void
    {
        [, $ownerId] = $this->seedOwnerWithBoard();
        $app = $this->termsApp();

        $first  = json_decode((string) $app->handle($this->request('POST', $this->acceptPath(), $ownerId))->getBody(), true);
        $second = json_decode((string) $app->handle($this->request('POST', $this->acceptPath(), $ownerId))->getBody(), true);

        self::assertSame($first['accepted_at'], $second['accepted_at']);
        self::assertSame(1, $this->consentRows($this->defaultAccountId()));
    }

    public function test_version_bump_asks_for_consent_again(): void
    {
        [$boardId, $ownerId] = $this->seedOwnerWithBoard();
        $accountId = $this->defaultAccountId();
        // The account accepted an earlier version of the clause.
        $this->conn->insert('upload_terms_consents', [
            'account_id'    => $accountId,
            'board_id'      => $boardId,
            'user_id'       => $ownerId,
            'terms_version' => '2026-09-old',
            'accepted_at'   => '2026-09-01 10:00:00',
        ]);
        $app = $this->termsApp();

        $response = $app->handle($this->request('POST', '/admin/boards/' . self::SLUG . '/assets/cover', $ownerId, [], ['image' => $this->upload($this->png(1600, 900))]));
        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('upload_terms_required', (string) $response->getBody());

        $app->handle($this->request('POST', $this->acceptPath(), $ownerId));
        $again = $app->handle($this->request('POST', '/admin/boards/' . self::SLUG . '/assets/cover', $ownerId, [], ['image' => $this->upload($this->png(1600, 900))]));
        self::assertSame(200, $again->getStatusCode());
        self::assertSame(2, $this->consentRows($accountId));
    }

    public function test_consent_of_another_account_does_not_apply(): void
    {
        [$boardId, $ownerId] = $this->seedOwnerWithBoard();
        $otherAccountId = $this->insertAccount(['slug' => 'other-acct', 'name' => 'Other']);
        $otherOwner     = $this->insertUser('other-owner@example.com');
        $this->insertAccountMember($otherAccountId, $otherOwner, 'owner');
        $this->conn->insert('upload_terms_consents', [
            'account_id'    => $otherAccountId,
            'board_id'      => null,
            'user_id'       => $otherOwner,
            'terms_version' => self::VERSION,
            'accepted_at'   => '2026-10-20 10:00:00',
        ]);
        $repo = new UploadTermsConsentRepository($this->conn);

        self::assertNotNull($repo->acceptedAt($otherAccountId, self::VERSION));
        self::assertNull($repo->acceptedAt($this->defaultAccountId(), self::VERSION));

        $response = $this->termsApp()->handle($this->request('POST', '/admin/boards/' . self::SLUG . '/assets/cover', $ownerId, [], ['image' => $this->upload($this->png(1600, 900))]));
        self::assertSame(403, $response->getStatusCode());
        self::assertSame(0, $this->uploadRows($boardId));
    }

    public function test_moderator_and_member_cannot_accept(): void
    {
        $this->seedOwnerWithBoard();
        $moderator = $this->seedUser('mod@example.com', 'moderator');
        $member    = $this->seedUser('member@example.com', 'member');
        $app       = $this->termsApp();

        self::assertSame(403, $app->handle($this->request('POST', $this->acceptPath(), $moderator))->getStatusCode());
        self::assertSame(403, $app->handle($this->request('POST', $this->acceptPath(), $member))->getStatusCode());
        self::assertSame(0, $this->consentRows($this->defaultAccountId()));
    }

    public function test_anonymous_request_is_refused(): void
    {
        $this->seedOwnerWithBoard();

        self::assertSame(401, $this->termsApp()->handle($this->request('POST', $this->acceptPath(), null))->getStatusCode());
        self::assertSame(0, $this->consentRows($this->defaultAccountId()));
    }

    public function test_frozen_board_refuses_accept_and_writes_nothing(): void
    {
        [$boardId, $ownerId] = $this->seedOwnerWithBoard();
        $this->conn->update('boards', ['frozen_at' => '2026-01-01 00:00:00'], ['id' => $boardId]);

        $response = $this->termsApp()->handle($this->request('POST', $this->acceptPath(), $ownerId));

        self::assertSame(423, $response->getStatusCode());
        self::assertSame(0, $this->consentRows($this->defaultAccountId()));
    }

    public function test_unknown_board_is_not_found_for_accept(): void
    {
        [, $ownerId] = $this->seedOwnerWithBoard();

        $response = $this->termsApp()->handle($this->request('POST', '/admin/boards/nope/assets/terms/accept', $ownerId));

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(0, $this->consentRows($this->defaultAccountId()));
    }

    public function test_consent_row_has_no_contact_data_and_stores_the_ui_language(): void
    {
        [, $ownerId] = $this->seedOwnerWithBoard();
        $this->termsApp()->handle($this->request('POST', $this->acceptPath(), $ownerId, ['lang' => 'fr']));

        $row = $this->conn->fetchAssociative('SELECT * FROM upload_terms_consents');

        self::assertIsArray($row);
        self::assertSame(
            ['id', 'account_id', 'board_id', 'user_id', 'terms_version', 'ui_language', 'accepted_at', 'account_pseudonym', 'board_pseudonym', 'pseudonymised_at'],
            array_keys($row),
        );
        self::assertSame(self::VERSION, $row['terms_version']);
        self::assertSame('fr', $row['ui_language']);
        self::assertSame($ownerId, (int) $row['user_id']);
        self::assertNull($row['pseudonymised_at']);
    }

    public function test_unsupported_ui_language_is_stored_as_null(): void
    {
        [, $ownerId] = $this->seedOwnerWithBoard();
        $response = $this->termsApp()->handle($this->request('POST', $this->acceptPath(), $ownerId, ['lang' => 'xx']));

        self::assertSame(200, $response->getStatusCode());
        self::assertNull($this->conn->fetchOne('SELECT ui_language FROM upload_terms_consents'));
    }

    public function test_erasure_pseudonymises_the_proof_and_unlinks_it_from_the_account(): void
    {
        [$boardId, $ownerId] = $this->seedOwnerWithBoard();
        $accountId = $this->defaultAccountId();
        $this->termsApp()->handle($this->request('POST', $this->acceptPath(), $ownerId, ['lang' => 'de']));
        $hasher = new IdentityHasher(self::identityServerKey());

        $repo = new UploadTermsConsentRepository($this->conn);
        self::assertSame(1, $repo->pseudonymiseForAccount($accountId, $hasher, new \DateTimeImmutable('2026-10-25 12:00:00')));

        $row = $this->conn->fetchAssociative('SELECT * FROM upload_terms_consents');
        self::assertIsArray($row);
        self::assertNull($row['account_id']);
        self::assertNull($row['board_id']);
        self::assertNull($row['user_id']);
        self::assertSame($hasher->hashReference('upload-terms-account', $accountId), $row['account_pseudonym']);
        self::assertSame($hasher->hashReference('upload-terms-board', $boardId), $row['board_pseudonym']);
        self::assertSame('2026-10-25 12:00:00', $row['pseudonymised_at']);
        self::assertSame('de', $row['ui_language']);
        self::assertSame(self::VERSION, $row['terms_version']);
        self::assertSame(64, strlen($row['account_pseudonym']));
        self::assertSame(0, $this->consentRows($accountId));
    }

    public function test_retention_purge_deletes_only_erased_proof_older_than_the_cutoff(): void
    {
        $accountId = $this->defaultAccountId();
        $repo      = new UploadTermsConsentRepository($this->conn);
        $this->conn->insert('upload_terms_consents', ['account_id' => $accountId, 'terms_version' => 'live', 'accepted_at' => '2026-01-01 00:00:00']);
        $this->conn->insert('upload_terms_consents', ['account_id' => null, 'terms_version' => 'old-erased', 'accepted_at' => '2020-01-01 00:00:00', 'pseudonymised_at' => '2020-06-01 00:00:00']);
        $this->conn->insert('upload_terms_consents', ['account_id' => null, 'terms_version' => 'recent-erased', 'accepted_at' => '2025-01-01 00:00:00', 'pseudonymised_at' => '2025-06-01 00:00:00']);

        self::assertSame(1, $repo->purgePseudonymisedBefore(new \DateTimeImmutable('2024-01-01 00:00:00')));

        self::assertSame(['live', 'recent-erased'], $this->conn->fetchFirstColumn('SELECT terms_version FROM upload_terms_consents ORDER BY id'));
    }

    public function test_without_a_configured_version_uploads_need_no_consent(): void
    {
        [$boardId, $ownerId] = $this->seedOwnerWithBoard();
        $app = $this->createApp();

        self::assertSame(200, $app->handle($this->request('POST', '/admin/boards/' . self::SLUG . '/assets/logo', $ownerId, [], ['image' => $this->upload($this->png(400, 400))]))->getStatusCode());
        self::assertSame(1, $this->uploadRows($boardId));
        self::assertSame(0, $this->consentRows($this->defaultAccountId()));
    }

    public function test_without_a_configured_version_the_list_has_no_upload_terms_block(): void
    {
        [, $ownerId] = $this->seedOwnerWithBoard();
        $response = $this->createApp()->handle($this->request('GET', '/admin/boards/' . self::SLUG . '/assets', $ownerId));

        self::assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        self::assertIsArray($payload);
        self::assertArrayNotHasKey('upload_terms', $payload);
    }

    public function test_without_a_configured_version_accept_is_404_and_records_nothing(): void
    {
        [, $ownerId] = $this->seedOwnerWithBoard();
        $response = $this->createApp()->handle($this->request('POST', $this->acceptPath(), $ownerId));

        self::assertSame(404, $response->getStatusCode());
        self::assertStringContainsString('upload_terms_not_configured', (string) $response->getBody());
        self::assertSame(0, $this->consentRows($this->defaultAccountId()));
    }
}
