<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\UploadedFile;
use Votepit\Config;
use Votepit\Security\BoardAssetProcessor;
use Votepit\Security\CsrfService;
use Votepit\Security\IdentityHasher;
use Votepit\Security\PublicIdGenerator;
use Votepit\Security\SessionService;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Board imagery and link/footer over HTTP (P-28, P-31 Lite part).
 *
 * Covers: owner/admin-only admin routes, plan gates per plan with downgrade
 * (kept, not applied) and re-upgrade (restored), invalid files, public serving
 * by opaque id scoped to the board in the URL, delete, and link validation.
 */
final class BoardAssetActionTest extends IntegrationTestCase
{
    private const APP_KEY = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    /**
     * @param positive-int $w
     * @param positive-int $h
     */
    private function png(int $w, int $h): string
    {
        $image = imagecreatetruecolor($w, $h);
        self::assertNotFalse($image);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 30, 90, 200));
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    private function seedUser(string $email, bool $admin): int
    {
        $this->conn->insert('users', [
            'public_id'     => PublicIdGenerator::generate(),
            'email_hmac'    => (new IdentityHasher(self::APP_KEY))->hash($email),
            'is_admin'      => 0,
            'is_blocked'    => 0,
            'token_version' => 0,
            'verified_at'   => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'created_at'    => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
        $userId = (int) $this->conn->lastInsertId();
        if ($admin) {
            $this->insertAccountMember($this->defaultAccountId(), $userId, 'owner');
        }

        return $userId;
    }

    /**
     * @param array<string, string> $fields
     * @param array<string, UploadedFile> $files
     */
    private function request(string $method, string $path, ?int $userId, array $fields = [], array $files = []): ServerRequestInterface
    {
        $csrf      = new CsrfService(self::APP_KEY, 3600, false);
        $token     = $csrf->generate();
        $cookies   = ['votepit_csrf' => $csrf->sign($token)];
        if ($userId !== null) {
            $cookies['votepit_sess'] = (new SessionService(self::APP_KEY, 3600, false))->sign(['uid' => $userId, 'v' => 0]);
        }
        $request = (new ServerRequestFactory())->createServerRequest($method, $path)
            ->withCookieParams($cookies)
            ->withParsedBody($fields + ['_csrf' => $token]);

        return $files === [] ? $request : $request->withUploadedFiles($files);
    }

    private function upload(string $bytes, string $name = 'image.png', string $type = 'image/png'): UploadedFile
    {
        return new UploadedFile((new StreamFactory())->createStream($bytes), $name, $type, strlen($bytes), UPLOAD_ERR_OK);
    }

    /**
     * Seeds an admin, the demo board and one uploaded cover + og on the current plan. Returns [boardId, adminId].
     *
     * @return array{int, int}
     */
    private function seedBoardWithImages(): array
    {
        $boardId = $this->insertBoard('demo', ['visibility' => 'public']);
        $adminId = $this->seedUser('admin@example.com', true);
        $this->setAccountPlan($this->defaultAccountId(), 'pro');
        $app = $this->createApp();
        $r = $app->handle($this->request('POST', '/admin/boards/demo/assets/cover', $adminId, [], ['image' => $this->upload($this->png(1600, 900))]));
        self::assertSame(200, $r->getStatusCode(), (string) $r->getBody());
        $r = $app->handle($this->request('POST', '/admin/boards/demo/assets/og', $adminId, [], ['image' => $this->upload($this->png(1200, 800))]));
        self::assertSame(200, $r->getStatusCode(), (string) $r->getBody());

        return [$boardId, $adminId];
    }

    /** @return array<string, string> variant => public id */
    private function publicIds(int $boardId): array
    {
        $rows = $this->conn->fetchAllAssociative('SELECT variant, public_id FROM board_assets WHERE board_id = ?', [$boardId]);

        return array_column($rows, 'public_id', 'variant');
    }

    // -------------------------------------------------------------------------
    // Permissions
    // -------------------------------------------------------------------------

    public function test_admin_routes_reject_anon_and_plain_members(): void
    {
        $this->insertBoard('demo', ['visibility' => 'public']);
        $plain = $this->seedUser('plain@example.com', false);
        $app   = $this->createApp();

        self::assertSame(401, $app->handle($this->request('GET', '/admin/boards/demo/assets', null))->getStatusCode());
        self::assertSame(403, $app->handle($this->request('GET', '/admin/boards/demo/assets', $plain))->getStatusCode());
        self::assertSame(403, $app->handle($this->request('POST', '/admin/boards/demo/assets/cover', $plain, [], ['image' => $this->upload($this->png(400, 400))]))->getStatusCode());
        self::assertSame(403, $app->handle($this->request('PUT', '/admin/boards/demo/link-footer', $plain, ['footer_note' => 'Hi']))->getStatusCode());
    }

    public function test_unknown_board_is_not_found_for_admin(): void
    {
        $admin = $this->seedUser('admin@example.com', true);

        $response = $this->createApp()->handle($this->request('GET', '/admin/boards/nope/assets', $admin));

        self::assertSame(404, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // Upload, validation, plan gate
    // -------------------------------------------------------------------------

    public function test_upload_on_business_stores_renditions_and_public_serve_works_for_anon(): void
    {
        [$boardId] = $this->seedBoardWithImages();
        $ids = $this->publicIds($boardId);
        self::assertEqualsCanonicalizing(
            [BoardAssetProcessor::VARIANT_COVER_WIDE, BoardAssetProcessor::VARIANT_COVER_PHONE, BoardAssetProcessor::VARIANT_OG],
            array_keys($ids),
        );

        $response = $this->createApp()->handle($this->request('GET', '/demo/assets/' . $ids[BoardAssetProcessor::VARIANT_OG], null));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('image/jpeg', $response->getHeaderLine('Content-Type'));
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertStringContainsString('immutable', $response->getHeaderLine('Cache-Control'));
        $info = getimagesizefromstring((string) $response->getBody());
        self::assertIsArray($info);
        self::assertSame([1200, 630], [$info[0], $info[1]]);
    }

    public function test_svg_and_html_uploads_are_rejected_with_422_and_nothing_is_stored(): void
    {
        $this->insertBoard('demo', ['visibility' => 'public']);
        $admin = $this->seedUser('admin@example.com', true);
        $this->setAccountPlan($this->defaultAccountId(), 'pro');
        $app = $this->createApp();

        $svg  = $this->upload('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'x.png');
        $html = $this->upload('<!doctype html><script>alert(1)</script>', 'x.png');
        $zero = $this->upload('', 'x.png');

        foreach ([$svg, $html, $zero] as $file) {
            $response = $app->handle($this->request('POST', '/admin/boards/demo/assets/favicon', $admin, [], ['image' => $file]));
            self::assertSame(422, $response->getStatusCode());
        }
        self::assertSame([], $this->conn->fetchAllAssociative('SELECT * FROM board_assets'));
    }

    public function test_team_plan_cannot_upload_but_business_can(): void
    {
        $this->insertBoard('demo', ['visibility' => 'public']);
        $admin = $this->seedUser('admin@example.com', true);
        $this->setAccountPlan($this->defaultAccountId(), 'lite');
        $app = $this->createApp();

        $locked = $app->handle($this->request('POST', '/admin/boards/demo/assets/cover', $admin, [], ['image' => $this->upload($this->png(1600, 900))]));
        self::assertSame(403, $locked->getStatusCode());
        $body = json_decode((string) $locked->getBody(), true);
        self::assertIsArray($body);
        self::assertSame('plan_feature_locked', $body['error']['key']);

        $this->setAccountPlan($this->defaultAccountId(), 'pro');
        $ok = $app->handle($this->request('POST', '/admin/boards/demo/assets/cover', $admin, [], ['image' => $this->upload($this->png(1600, 900))]));
        self::assertSame(200, $ok->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // Downgrade (kept, not applied) and re-upgrade (restored)
    // -------------------------------------------------------------------------

    public function test_downgrade_keeps_images_but_stops_serving_them_and_reupgrade_restores_them(): void
    {
        [$boardId] = $this->seedBoardWithImages();
        $id        = $this->publicIds($boardId)[BoardAssetProcessor::VARIANT_OG];
        $app       = $this->createApp();

        $this->setAccountPlan($this->defaultAccountId(), 'lite');
        self::assertSame(404, $app->handle($this->request('GET', '/demo/assets/' . $id, null))->getStatusCode());
        self::assertCount(3, $this->publicIds($boardId), 'rows stay in place on downgrade');

        $this->setAccountPlan($this->defaultAccountId(), 'pro');
        self::assertSame(200, $app->handle($this->request('GET', '/demo/assets/' . $id, null))->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // Public serving: opaque id, scoped to the board in the URL
    // -------------------------------------------------------------------------

    public function test_asset_of_another_board_is_not_served_under_this_board(): void
    {
        [$boardId] = $this->seedBoardWithImages();
        $this->insertBoard('other', ['visibility' => 'public']);
        $id  = $this->publicIds($boardId)[BoardAssetProcessor::VARIANT_OG];
        $app = $this->createApp();

        self::assertSame(404, $app->handle($this->request('GET', '/other/assets/' . $id, null))->getStatusCode());
        self::assertSame(404, $app->handle($this->request('GET', '/demo/assets/' . str_repeat('0', 32), null))->getStatusCode());
        self::assertSame(404, $app->handle($this->request('GET', '/demo/assets/not-an-id', null))->getStatusCode());
    }

    public function test_private_board_assets_are_not_served_anonymously(): void
    {
        [$boardId] = $this->seedBoardWithImages();
        $this->conn->update('boards', ['visibility' => 'private'], ['id' => $boardId]);
        $id = $this->publicIds($boardId)[BoardAssetProcessor::VARIANT_OG];

        self::assertSame(404, $this->createApp()->handle($this->request('GET', '/demo/assets/' . $id, null))->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // Delete
    // -------------------------------------------------------------------------

    public function test_admin_delete_removes_rows_and_stops_serving(): void
    {
        [$boardId, $admin] = $this->seedBoardWithImages();
        $id  = $this->publicIds($boardId)[BoardAssetProcessor::VARIANT_OG];
        $app = $this->createApp();

        self::assertSame(200, $app->handle($this->request('POST', '/admin/boards/demo/assets/og/delete', $admin))->getStatusCode());
        self::assertArrayNotHasKey(BoardAssetProcessor::VARIANT_OG, $this->publicIds($boardId));
        self::assertSame(404, $app->handle($this->request('GET', '/demo/assets/' . $id, null))->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // Back link and footer note
    // -------------------------------------------------------------------------

    public function test_link_footer_is_gated_by_plan_but_clearing_is_always_allowed(): void
    {
        $boardId = $this->insertBoard('demo', ['visibility' => 'public']);
        $admin   = $this->seedUser('admin@example.com', true);
        $app     = $this->createApp();

        $this->setAccountPlan($this->defaultAccountId(), 'lite');
        $locked = $app->handle($this->request('PUT', '/admin/boards/demo/link-footer', $admin, [
            'back_link_url' => 'https://example.com', 'back_link_label' => 'Home', 'footer_note' => '',
        ]));
        self::assertSame(422, $locked->getStatusCode());
        $fields = json_decode((string) $locked->getBody(), true)['error']['fields'];
        self::assertArrayHasKey('back_link_url', $fields);

        $this->setAccountPlan($this->defaultAccountId(), 'pro');
        self::assertSame(200, $app->handle($this->request('PUT', '/admin/boards/demo/link-footer', $admin, [
            'back_link_url' => 'https://example.com/help', 'back_link_label' => 'Zurück', 'footer_note' => 'Betrieben von Acme & Co.',  // export-ok: comment-language
        ]))->getStatusCode());
        $row = $this->conn->fetchAssociative('SELECT back_link_url, back_link_label, footer_note FROM boards WHERE id = ?', [$boardId]);
        self::assertIsArray($row);
        self::assertSame(['https://example.com/help', 'Zurück', 'Betrieben von Acme & Co.'], [$row['back_link_url'], $row['back_link_label'], $row['footer_note']]);  // export-ok: comment-language

        $this->setAccountPlan($this->defaultAccountId(), 'lite');
        self::assertSame(200, $app->handle($this->request('PUT', '/admin/boards/demo/link-footer', $admin, [
            'back_link_url' => '', 'back_link_label' => '', 'footer_note' => '',
        ]))->getStatusCode());
    }

    public function test_invalid_back_link_is_rejected_with_field_error(): void
    {
        $this->insertBoard('demo', ['visibility' => 'public']);
        $admin = $this->seedUser('admin@example.com', true);
        $this->setAccountPlan($this->defaultAccountId(), 'pro');

        $response = $this->createApp()->handle($this->request('PUT', '/admin/boards/demo/link-footer', $admin, [
            'back_link_url' => 'javascript:alert(1)', 'back_link_label' => 'Home', 'footer_note' => '',
        ]));

        self::assertSame(422, $response->getStatusCode());
        $fields = json_decode((string) $response->getBody(), true)['error']['fields'];
        self::assertArrayHasKey('back_link_url', $fields);
    }

    public function test_admin_list_returns_the_stored_link_values_and_plan_flags(): void
    {
        $this->insertBoard('demo', ['visibility' => 'public', 'back_link_url' => 'https://example.com/help', 'back_link_label' => 'Hilfe', 'footer_note' => 'Notiz']);
        $admin = $this->seedUser('admin@example.com', true);
        $this->setAccountPlan($this->defaultAccountId(), 'lite');

        $response = $this->createApp()->handle($this->request('GET', '/admin/boards/demo/assets', $admin));

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame(['back_link_url' => 'https://example.com/help', 'back_link_label' => 'Hilfe', 'footer_note' => 'Notiz'], $body['link']);
        self::assertFalse($body['allowed']['cover_upload']);
        self::assertFalse($body['allowed']['back_link_footer']);
    }

    // -------------------------------------------------------------------------
    // Logo upload (P-28b): upload instead of a link, Lite+ gate, rejections,
    // delete, frozen guard, cleanup and the public payload.
    // -------------------------------------------------------------------------

    /**
     * @param positive-int $w
     * @param positive-int $h
     */
    private function logoPng(int $w, int $h): string
    {
        $image = imagecreatetruecolor($w, $h);
        self::assertNotFalse($image);
        imagesavealpha($image, true);
        imagealphablending($image, false);
        imagefill($image, 0, 0, (int) imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagefilledrectangle($image, 0, (int) ($h / 3), $w - 1, (int) (2 * $h / 3), (int) imagecolorallocatealpha($image, 200, 40, 40, 0));
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    /** @return array{key: string, fields?: array<string, string>} */
    private function errorOf(\Psr\Http\Message\ResponseInterface $response): array
    {
        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);

        return $body['error'];
    }

    public function test_logo_upload_on_lite_stores_a_256_png_that_serves_for_anon(): void
    {
        $this->insertBoard('demo', ['visibility' => 'public']);
        $admin = $this->seedUser('admin@example.com', true);
        $this->setAccountPlan($this->defaultAccountId(), 'lite');
        $app = $this->createApp();

        $response = $app->handle($this->request('POST', '/admin/boards/demo/assets/logo', $admin, [], ['image' => $this->upload($this->logoPng(800, 300))]));
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $boardId = (int) $this->conn->fetchOne("SELECT id FROM boards WHERE slug = 'demo'");
        $ids     = $this->publicIds($boardId);
        self::assertSame([BoardAssetProcessor::VARIANT_LOGO], array_keys($ids));

        $served = $app->handle($this->request('GET', '/demo/assets/' . $ids[BoardAssetProcessor::VARIANT_LOGO], null));
        self::assertSame(200, $served->getStatusCode());
        self::assertSame('image/png', $served->getHeaderLine('Content-Type'));
        self::assertSame('nosniff', $served->getHeaderLine('X-Content-Type-Options'));
        self::assertSame([256, 256], array_slice((array) getimagesizefromstring((string) $served->getBody()), 0, 2));
    }

    public function test_logo_upload_is_refused_on_free_and_the_admin_list_reports_the_gate(): void
    {
        $this->insertBoard('demo', ['visibility' => 'public']);
        $admin = $this->seedUser('admin@example.com', true);
        $this->setAccountPlan($this->defaultAccountId(), 'free');
        $app = $this->createApp();

        $locked = $app->handle($this->request('POST', '/admin/boards/demo/assets/logo', $admin, [], ['image' => $this->upload($this->logoPng(400, 400))]));
        self::assertSame(403, $locked->getStatusCode());
        self::assertSame('plan_feature_locked', $this->errorOf($locked)['key']);

        $list = json_decode((string) $app->handle($this->request('GET', '/admin/boards/demo/assets', $admin))->getBody(), true);
        self::assertIsArray($list);
        self::assertFalse($list['allowed']['logo_url']);
        self::assertSame(2 * 1024 * 1024, $list['max_upload_bytes_by_kind']['logo']);
    }

    public function test_logo_rejections_name_the_problem_and_store_nothing(): void
    {
        $this->insertBoard('demo', ['visibility' => 'public']);
        $admin = $this->seedUser('admin@example.com', true);
        $this->setAccountPlan($this->defaultAccountId(), 'lite');
        $app = $this->createApp();

        $svgAsPng = $this->upload('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'logo.png');
        $htmlAsPng = $this->upload('<!doctype html><script>alert(1)</script>', 'logo.png');
        $small    = $this->upload($this->logoPng(120, 120), 'logo.png');
        $oversized = $this->upload($this->logoPng(300, 300) . str_repeat("\0", 2 * 1024 * 1024 + 16), 'logo.png');

        $expected = [
            'unsupported_format' => $svgAsPng,
            'image_too_small'    => $small,
            'file_too_large'     => $oversized,
        ];
        foreach ($expected as $key => $file) {
            $response = $app->handle($this->request('POST', '/admin/boards/demo/assets/logo', $admin, [], ['image' => $file]));
            self::assertSame(422, $response->getStatusCode(), $key);
            self::assertSame($key, $this->errorOf($response)['key']);
        }
        $html = $app->handle($this->request('POST', '/admin/boards/demo/assets/logo', $admin, [], ['image' => $htmlAsPng]));
        self::assertSame('unsupported_format', $this->errorOf($html)['key']);

        self::assertSame([], $this->conn->fetchAllAssociative('SELECT * FROM board_assets'));
    }

    public function test_logo_delete_removes_the_row_and_stops_serving(): void
    {
        $this->insertBoard('demo', ['visibility' => 'public']);
        $admin = $this->seedUser('admin@example.com', true);
        $this->setAccountPlan($this->defaultAccountId(), 'lite');
        $app = $this->createApp();
        $app->handle($this->request('POST', '/admin/boards/demo/assets/logo', $admin, [], ['image' => $this->upload($this->logoPng(400, 400))]));
        $boardId = (int) $this->conn->fetchOne("SELECT id FROM boards WHERE slug = 'demo'");
        $id      = $this->publicIds($boardId)[BoardAssetProcessor::VARIANT_LOGO];

        self::assertSame(200, $app->handle($this->request('POST', '/admin/boards/demo/assets/logo/delete', $admin))->getStatusCode());
        self::assertArrayNotHasKey(BoardAssetProcessor::VARIANT_LOGO, $this->publicIds($boardId));
        self::assertSame(404, $app->handle($this->request('GET', '/demo/assets/' . $id, null))->getStatusCode());
    }

    public function test_logo_of_another_board_is_not_served_under_this_board(): void
    {
        $this->insertBoard('demo', ['visibility' => 'public']);
        $this->insertBoard('other', ['visibility' => 'public']);
        $admin = $this->seedUser('admin@example.com', true);
        $this->setAccountPlan($this->defaultAccountId(), 'lite');
        $app = $this->createApp();
        $app->handle($this->request('POST', '/admin/boards/demo/assets/logo', $admin, [], ['image' => $this->upload($this->logoPng(400, 400))]));
        $id = $this->publicIds((int) $this->conn->fetchOne("SELECT id FROM boards WHERE slug = 'demo'"))[BoardAssetProcessor::VARIANT_LOGO];

        self::assertSame(404, $app->handle($this->request('GET', '/other/assets/' . $id, null))->getStatusCode());
    }

    public function test_frozen_board_cannot_change_its_logo(): void
    {
        $boardId = $this->insertBoard('demo', ['visibility' => 'public']);
        $admin   = $this->seedUser('admin@example.com', true);
        $this->setAccountPlan($this->defaultAccountId(), 'lite');
        $this->conn->update('boards', ['frozen_at' => '2026-10-01 10:00:00'], ['id' => $boardId]);

        $response = $this->createApp()->handle($this->request('POST', '/admin/boards/demo/assets/logo', $admin, [], ['image' => $this->upload($this->logoPng(400, 400))]));

        self::assertSame(423, $response->getStatusCode());
        self::assertSame([], $this->conn->fetchAllAssociative('SELECT * FROM board_assets'));
    }

    public function test_board_public_payload_carries_the_uploaded_logo_only_while_the_plan_allows_it(): void
    {
        $this->insertBoard('demo', ['visibility' => 'public']);
        $admin = $this->seedUser('admin@example.com', true);
        $this->setAccountPlan($this->defaultAccountId(), 'lite');
        $app = $this->createApp();
        $app->handle($this->request('POST', '/admin/boards/demo/assets/logo', $admin, [], ['image' => $this->upload($this->logoPng(400, 400))]));
        $id = $this->publicIds((int) $this->conn->fetchOne("SELECT id FROM boards WHERE slug = 'demo'"))[BoardAssetProcessor::VARIANT_LOGO];

        $payload = fn (): mixed => json_decode((string) $app->handle($this->request('GET', '/demo', null))->getBody(), true)['board'];
        $board   = $payload();
        self::assertSame($id, $board['logo_public_id']);
        self::assertSame($id, $board['assets']['logo']);
        self::assertIsFloat($board['asset_ink']['logo'] ?? null, 'contrast metadata travels with the logo slot');
        self::assertTrue($board['logo_allowed']);
        self::assertNull($board['logo_url'], 'no legacy link is shown while an upload exists');

        $this->setAccountPlan($this->defaultAccountId(), 'free');
        $board = $payload();
        self::assertNull($board['logo_public_id'], 'downgrade: stored logo kept, not applied');
        self::assertFalse($board['logo_allowed']);
        self::assertArrayNotHasKey('logo', $board['assets']);
        self::assertArrayNotHasKey('logo', $board['asset_ink']);

        $this->setAccountPlan($this->defaultAccountId(), 'lite');
        self::assertSame($id, $payload()['logo_public_id'], 're-upgrade restores it without a write');
    }

    public function test_logo_cleanup_removes_the_files_when_the_board_is_purged(): void
    {
        $boardId = $this->insertBoard('demo', ['visibility' => 'public']);
        $admin   = $this->seedUser('admin@example.com', true);
        $this->setAccountPlan($this->defaultAccountId(), 'lite');
        $this->createApp()->handle($this->request('POST', '/admin/boards/demo/assets/logo', $admin, [], ['image' => $this->upload($this->logoPng(400, 400))]));
        $files = glob($this->boardAssetDir . '/*');
        self::assertIsArray($files);
        self::assertCount(1, $files);

        $cleanup = new \Votepit\Cleanup\BoardAssetCleanup(
            new \Votepit\Persistence\BoardAssetRepository($this->conn),
            new \Votepit\Security\BoardAssetFileStore($this->boardAssetDir),
        );
        $cleanup->purgeForBoard($boardId);

        self::assertSame([], glob($this->boardAssetDir . '/*'));
    }

    public function test_wordmark_upload_on_lite_stores_1200x300_serves_for_anon_and_keeps_the_symbol_slot(): void
    {
        $this->insertBoard('demo', ['visibility' => 'public']);
        $admin = $this->seedUser('admin@example.com', true);
        $this->setAccountPlan($this->defaultAccountId(), 'lite');
        $app = $this->createApp();

        self::assertSame(200, $app->handle($this->request('POST', '/admin/boards/demo/assets/logo', $admin, [], ['image' => $this->upload($this->logoPng(800, 300))]))->getStatusCode());
        $response = $app->handle($this->request('POST', '/admin/boards/demo/assets/wordmark', $admin, [], ['image' => $this->upload($this->logoPng(1600, 400))]));
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $boardId = (int) $this->conn->fetchOne("SELECT id FROM boards WHERE slug = 'demo'");
        $ids     = $this->publicIds($boardId);
        self::assertSame([BoardAssetProcessor::VARIANT_LOGO, BoardAssetProcessor::VARIANT_WORDMARK], array_keys($ids));

        $served = $app->handle($this->request('GET', '/demo/assets/' . $ids[BoardAssetProcessor::VARIANT_WORDMARK], null));
        self::assertSame(200, $served->getStatusCode());
        self::assertSame('image/png', $served->getHeaderLine('Content-Type'));
        self::assertSame([1200, 300], array_slice((array) getimagesizefromstring((string) $served->getBody()), 0, 2));

        $list = json_decode((string) $app->handle($this->request('GET', '/admin/boards/demo/assets', $admin))->getBody(), true);
        self::assertIsFloat($list['variants']['wordmark']['ink'] ?? null, 'the settings field reads the contrast metadata');
        self::assertSame(1200, $list['variants']['wordmark']['width']);
    }

    public function test_light_wordmark_upload_on_lite_stores_serves_and_reaches_the_payload(): void
    {
        $this->insertBoard('demo', ['visibility' => 'public']);
        $admin = $this->seedUser('admin@example.com', true);
        $this->setAccountPlan($this->defaultAccountId(), 'lite');
        $app = $this->createApp();

        $response = $app->handle($this->request('POST', '/admin/boards/demo/assets/wordmark_light', $admin, [], ['image' => $this->upload($this->logoPng(1600, 400))]));
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $boardId = (int) $this->conn->fetchOne("SELECT id FROM boards WHERE slug = 'demo'");
        $id      = $this->publicIds($boardId)[BoardAssetProcessor::VARIANT_WORDMARK_LIGHT];
        $served  = $app->handle($this->request('GET', '/demo/assets/' . $id, null));
        self::assertSame(200, $served->getStatusCode());
        self::assertSame([1200, 300], array_slice((array) getimagesizefromstring((string) $served->getBody()), 0, 2));

        $board = json_decode((string) $app->handle($this->request('GET', '/demo', null))->getBody(), true)['board'];
        self::assertSame($id, $board['wordmark_light_public_id']);
        self::assertIsFloat($board['wordmark_light_ink']);
        self::assertNull($board['wordmark_public_id'], 'the light slot does not fill the normal wordmark');
    }

    public function test_light_wordmark_slot_of_another_account_is_not_reachable(): void
    {
        $otherAccount = $this->insertAccount(['slug' => 'other', 'name' => 'Other']);
        $this->insertBoard('elsewhere', ['account_id' => $otherAccount, 'visibility' => 'public']);
        $this->insertBoard('demo', ['visibility' => 'public']);
        $admin = $this->seedUser('admin@example.com', true);
        $this->setAccountPlan($this->defaultAccountId(), 'lite');
        $app = $this->createApp();

        $response = $app->handle($this->request('POST', '/admin/boards/elsewhere/assets/wordmark_light', $admin, [], ['image' => $this->upload($this->logoPng(1600, 400))]));
        self::assertSame(404, $response->getStatusCode());
        $stored = (int) $this->conn->fetchOne("SELECT COUNT(*) FROM board_assets WHERE variant = 'wordmark_light'");
        self::assertSame(0, $stored, 'nothing is written into the foreign board');
    }

    public function test_light_wordmark_is_dropped_from_the_payload_on_free_but_kept_in_storage(): void
    {
        $this->insertBoard('demo', ['visibility' => 'public']);
        $admin = $this->seedUser('admin@example.com', true);
        $this->setAccountPlan($this->defaultAccountId(), 'lite');
        $app = $this->createApp();
        $app->handle($this->request('POST', '/admin/boards/demo/assets/wordmark_light', $admin, [], ['image' => $this->upload($this->logoPng(1600, 400))]));

        $this->setAccountPlan($this->defaultAccountId(), 'free');
        $board = json_decode((string) $app->handle($this->request('GET', '/demo', null))->getBody(), true)['board'];
        self::assertNull($board['wordmark_light_public_id'], 'downgrade: stored light wordmark kept, not applied');
        self::assertNull($board['wordmark_light_ink']);
    }

    public function test_wordmark_is_refused_on_free_with_the_same_gate_as_the_logo(): void
    {
        $this->insertBoard('demo', ['visibility' => 'public']);
        $admin = $this->seedUser('admin@example.com', true);
        $this->setAccountPlan($this->defaultAccountId(), 'free');
        $app = $this->createApp();

        $response = $app->handle($this->request('POST', '/admin/boards/demo/assets/wordmark', $admin, [], ['image' => $this->upload($this->logoPng(800, 300))]));
        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('plan_feature_locked', (string) $response->getBody());
    }

    public function test_wordmark_delete_removes_only_its_own_row(): void
    {
        $this->insertBoard('demo', ['visibility' => 'public']);
        $admin = $this->seedUser('admin@example.com', true);
        $this->setAccountPlan($this->defaultAccountId(), 'lite');
        $app = $this->createApp();

        $app->handle($this->request('POST', '/admin/boards/demo/assets/logo', $admin, [], ['image' => $this->upload($this->logoPng(800, 300))]));
        $app->handle($this->request('POST', '/admin/boards/demo/assets/wordmark', $admin, [], ['image' => $this->upload($this->logoPng(1600, 400))]));
        self::assertSame(200, $app->handle($this->request('POST', '/admin/boards/demo/assets/wordmark/delete', $admin, [], []))->getStatusCode());

        $boardId = (int) $this->conn->fetchOne("SELECT id FROM boards WHERE slug = 'demo'");
        self::assertSame([BoardAssetProcessor::VARIANT_LOGO], array_keys($this->publicIds($boardId)));
    }

    public function test_board_payload_carries_the_wordmark_and_its_ink_only_while_the_logo_plan_allows_it(): void
    {
        $this->insertBoard('demo', ['visibility' => 'public']);
        $admin = $this->seedUser('admin@example.com', true);
        $this->setAccountPlan($this->defaultAccountId(), 'lite');
        $app = $this->createApp();
        $app->handle($this->request('POST', '/admin/boards/demo/assets/wordmark', $admin, [], ['image' => $this->upload($this->logoPng(1600, 400))]));
        $id = $this->publicIds((int) $this->conn->fetchOne("SELECT id FROM boards WHERE slug = 'demo'"))[BoardAssetProcessor::VARIANT_WORDMARK];

        $payload = fn (): mixed => json_decode((string) $app->handle($this->request('GET', '/demo', null))->getBody(), true)['board'];
        $board   = $payload();
        self::assertSame($id, $board['wordmark_public_id']);
        self::assertIsFloat($board['wordmark_ink']);

        $this->setAccountPlan($this->defaultAccountId(), 'free');
        $board = $payload();
        self::assertNull($board['wordmark_public_id'], 'downgrade: stored wordmark kept, not applied');
        self::assertNull($board['wordmark_ink']);

        $this->setAccountPlan($this->defaultAccountId(), 'lite');
        self::assertSame($id, $payload()['wordmark_public_id'], 're-upgrade restores it without a write');
    }

    // -------------------------------------------------------------------------
    // Installation kill switch (Config::$boardUploadsEnabled)
    // -------------------------------------------------------------------------

    public function test_kill_switch_off_refuses_uploads_and_stores_nothing(): void
    {
        $boardId = $this->insertBoard('demo', ['visibility' => 'public']);
        $adminId = $this->seedUser('admin@example.com', true);
        $this->setAccountPlan($this->defaultAccountId(), 'pro');

        $response = $this->createApp(config: $this->configWithUploads(false))
            ->handle($this->request('POST', '/admin/boards/demo/assets/cover', $adminId, [], ['image' => $this->upload($this->png(1600, 900))]));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('board_uploads_unavailable', $this->errorOf($response)['key']);
        self::assertSame([], $this->publicIds($boardId));
    }

    public function test_kill_switch_off_stops_serving_stored_images_and_keeps_them_for_a_switch_back_on(): void
    {
        [$boardId] = $this->seedBoardWithImages();
        $ids = $this->publicIds($boardId);
        $url = '/demo/assets/' . $ids[BoardAssetProcessor::VARIANT_OG];

        self::assertSame(404, $this->createApp(config: $this->configWithUploads(false))->handle($this->request('GET', $url, null))->getStatusCode());
        self::assertSame(200, $this->createApp()->handle($this->request('GET', $url, null))->getStatusCode(), 'default on: served as before');
        self::assertSame($ids, $this->publicIds($boardId), 'rows are kept while off');
    }

    public function test_kill_switch_off_keeps_the_owner_able_to_remove_an_image(): void
    {
        [$boardId, $adminId] = $this->seedBoardWithImages();

        $response = $this->createApp(config: $this->configWithUploads(false))
            ->handle($this->request('POST', '/admin/boards/demo/assets/og/delete', $adminId));

        self::assertSame(200, $response->getStatusCode());
        self::assertArrayNotHasKey(BoardAssetProcessor::VARIANT_OG, $this->publicIds($boardId));
    }

    private function configWithUploads(bool $enabled): Config
    {
        return Config::fromArray($this->testConfigArray(['board_uploads' => $enabled]));
    }

    /**
     * Gate per upload kind, the stored variants it produces, and an upload size that passes the processor.
     *
     * @return list<array{kind: string, width: positive-int, height: positive-int, gate: string, variants: list<string>}>
     */
    private function uploadKinds(): array
    {
        return [
            ['kind' => 'cover', 'width' => 1600, 'height' => 900, 'gate' => 'cover_upload', 'variants' => ['cover_wide', 'cover_phone']],
            ['kind' => 'favicon', 'width' => 512, 'height' => 512, 'gate' => 'favicon_og', 'variants' => ['favicon']],
            ['kind' => 'og', 'width' => 1200, 'height' => 800, 'gate' => 'favicon_og', 'variants' => ['og']],
            ['kind' => 'logo', 'width' => 800, 'height' => 300, 'gate' => 'logo_url', 'variants' => ['logo']],
            ['kind' => 'wordmark', 'width' => 1600, 'height' => 400, 'gate' => 'logo_url', 'variants' => ['wordmark']],
            ['kind' => 'wordmark_light', 'width' => 1600, 'height' => 400, 'gate' => 'logo_url', 'variants' => ['wordmark_light']],
        ];
    }

    /** Gates each effective plan includes (synthetic policy in createApp()). */
    private const ALLOWED_GATES = [
        'free' => [],
        'lite' => ['logo_url'],
        'pro'  => ['cover_upload', 'logo_url', 'favicon_og'],
    ];

    /** @return iterable<string, array{string, bool}> */
    public static function planScenarios(): iterable
    {
        yield 'free' => ['free', false];
        yield 'lite' => ['lite', false];
        yield 'pro' => ['pro', false];
        yield 'free account with the operator as member' => ['free', true];
    }

    /**
     * For every asset variant the admin upload decision and the public serve
     * decision agree: an allowed upload is served 200, a gated upload is refused
     * and its previously stored image is served 404 (downgrade keeps the file,
     * stops serving it). The operator-member account behaves as pro.
     */
    #[DataProvider('planScenarios')]
    public function test_upload_and_public_serve_agree_for_every_variant(string $plan, bool $operatorMember): void
    {
        $this->insertBoard('demo', ['visibility' => 'public']);
        $adminId = $this->seedUser('admin@example.com', true);
        $accountId = $this->defaultAccountId();
        $this->setAccountPlan($accountId, 'pro');
        $app = $this->createApp();

        // Store every variant while on pro, so the scenario has images to refuse to serve.
        foreach ($this->uploadKinds() as $upload) {
            $response = $app->handle($this->request('POST', '/admin/boards/demo/assets/' . $upload['kind'], $adminId, [], ['image' => $this->upload($this->png($upload['width'], $upload['height']))]));
            self::assertSame(200, $response->getStatusCode(), $upload['kind'] . ' on pro: ' . $response->getBody());
        }

        $this->setAccountPlan($accountId, $plan);
        if ($operatorMember) {
            $operatorId = $this->insertUser('operator@example.com', ['is_operator' => 1]);
            $this->insertAccountMember($accountId, $operatorId, 'member');
        }
        $effective = $operatorMember ? 'pro' : $plan;
        $allowedGates = self::ALLOWED_GATES[$effective];

        foreach ($this->uploadKinds() as $upload) {
            $allowed = in_array($upload['gate'], $allowedGates, true);
            $response = $app->handle($this->request('POST', '/admin/boards/demo/assets/' . $upload['kind'], $adminId, [], ['image' => $this->upload($this->png($upload['width'], $upload['height']))]));
            self::assertSame($allowed ? 200 : 403, $response->getStatusCode(), $upload['kind'] . ' upload decision');
        }

        $boardId = (int) $this->conn->fetchOne("SELECT id FROM boards WHERE slug = 'demo'");
        $ids = $this->publicIds($boardId);
        foreach ($this->uploadKinds() as $upload) {
            $allowed = in_array($upload['gate'], $allowedGates, true);
            foreach ($upload['variants'] as $variant) {
                self::assertArrayHasKey($variant, $ids);
                $served = $app->handle($this->request('GET', '/demo/assets/' . $ids[$variant], null))->getStatusCode();
                self::assertSame($allowed ? 200 : 404, $served, $variant . ' public serve decision');
            }
        }
    }
}
