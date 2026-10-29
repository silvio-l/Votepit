<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Votepit\Domain\UnrestrictedPlanPolicy;
use Votepit\Http\Support\BoardShell;
use Votepit\Security\BoardAssetProcessor;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Per-board meta in the SPA shell (P-28). Escaping, the plan gate, the
 * private-board case and the fail-open fallback are pinned here.
 */
final class BoardShellTest extends IntegrationTestCase
{
    /** @var list<string> temporary config files written by a test, removed in tearDown() */
    private array $configFiles = [];

    private const SHELL = '<!doctype html><html><head>
    <link rel="icon" type="image/svg+xml" href="/favicon.svg" />
    <link rel="shortcut icon" href="/favicon.ico" />
    <link rel="apple-touch-icon" href="/apple-touch-icon.png" />
    <title>Votepit</title>
  </head><body><div id="root"></div></body></html>';

    private function seedPublicBoard(string $name = 'Demo Board'): int
    {
        return $this->insertBoard('demo', ['visibility' => 'public', 'name' => $name]);
    }

    private function seedAsset(int $boardId, string $variant, string $publicId): void
    {
        $this->conn->insert('board_assets', [
            'board_id'     => $boardId,
            'variant'      => $variant,
            'public_id'    => $publicId,
            'file_name'    => str_repeat('a', 32) . '.jpg',
            'content_type' => 'image/jpeg',
            'width'        => 1200,
            'height'       => 630,
            'created_at'   => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    private function render(string $path): ?string
    {
        return BoardShell::render(self::SHELL, $path, $this->testConfig(), $this->conn, self::syntheticPlanPolicy());
    }

    public function test_public_board_on_a_plan_with_the_gate_gets_favicon_and_share_image(): void
    {
        $boardId = $this->seedPublicBoard();
        $this->setAccountPlan($this->defaultAccountId(), 'pro');
        $og = str_repeat('b', 32);
        $fav = str_repeat('c', 32);
        $this->seedAsset($boardId, BoardAssetProcessor::VARIANT_OG, $og);
        $this->seedAsset($boardId, BoardAssetProcessor::VARIANT_FAVICON, $fav);

        $html = (string) $this->render('/demo');

        self::assertStringContainsString('<meta property="og:image" content="http://localhost:8000/demo/assets/' . $og . '">', $html);
        self::assertStringContainsString('<meta name="twitter:card" content="summary_large_image">', $html);
        self::assertStringContainsString('<link rel="icon" type="image/png" sizes="64x64" href="http://localhost:8000/demo/assets/' . $fav . '">', $html);
        self::assertStringNotContainsString('href="/favicon.svg"', $html, 'the board favicon replaces the static icons');
        self::assertSame(1, substr_count($html, '<title>'));
        self::assertStringContainsString('<title>Demo Board · Votepit</title>', $html);
    }

    public function test_board_touch_icon_replaces_the_static_apple_touch_icon(): void
    {
        $boardId = $this->seedPublicBoard();
        $this->setAccountPlan($this->defaultAccountId(), 'pro');
        $touch = str_repeat('e', 32);
        $this->seedAsset($boardId, BoardAssetProcessor::VARIANT_FAVICON, str_repeat('c', 32));
        $this->seedAsset($boardId, BoardAssetProcessor::VARIANT_TOUCH_ICON, $touch);

        $html = (string) $this->render('/demo');

        self::assertStringContainsString('<link rel="apple-touch-icon" sizes="180x180" href="http://localhost:8000/demo/assets/' . $touch . '">', $html);
        self::assertStringNotContainsString('href="/apple-touch-icon.png"', $html);
        self::assertSame(1, substr_count($html, 'rel="apple-touch-icon"'));
    }

    public function test_favicon_without_touch_rendition_is_the_apple_touch_icon_too(): void
    {
        $boardId = $this->seedPublicBoard();
        $this->setAccountPlan($this->defaultAccountId(), 'pro');
        $fav = str_repeat('c', 32);
        $this->seedAsset($boardId, BoardAssetProcessor::VARIANT_FAVICON, $fav);

        $html = (string) $this->render('/demo');

        self::assertStringContainsString('<link rel="apple-touch-icon" href="http://localhost:8000/demo/assets/' . $fav . '">', $html);
        self::assertStringNotContainsString('href="/apple-touch-icon.png"', $html);
    }

    public function test_idea_submit_impressum_and_edit_paths_get_the_board_icon(): void
    {
        $boardId = $this->seedPublicBoard();
        $this->setAccountPlan($this->defaultAccountId(), 'pro');
        $fav = str_repeat('d', 32);
        $this->seedAsset($boardId, BoardAssetProcessor::VARIANT_FAVICON, $fav);
        $favHref = 'href="http://localhost:8000/demo/assets/' . $fav . '"';

        foreach (['/demo/roadmap', '/demo/submit', '/demo/impressum', '/demo/idea/12', '/demo/idea/12/edit'] as $path) {
            $html = (string) $this->render($path);
            self::assertStringContainsString($favHref, $html, $path);
        }
    }

    public function test_board_sub_paths_outside_the_board_pages_get_the_plain_shell(): void
    {
        $this->seedPublicBoard();

        self::assertNull($this->render('/demo/idea/abc'));
        self::assertNull($this->render('/demo/idea/12/delete'));
        self::assertNull($this->render('/demo/widget'));
        self::assertNull($this->render('/demo/poster'));
    }

    public function test_board_name_is_escaped_in_title_and_meta(): void
    {
        $this->seedPublicBoard('</title><script>alert(1)</script> "Q" & co');
        $this->setAccountPlan($this->defaultAccountId(), 'self-host');

        $html = (string) $this->render('/demo');

        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringContainsString('&lt;/title&gt;&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringContainsString('content="&lt;/title&gt;&lt;script&gt;alert(1)&lt;/script&gt; &quot;Q&quot; &amp; co"', $html);
    }

    public function test_gate_off_plan_gets_no_image_tags_but_keeps_title(): void
    {
        $boardId = $this->seedPublicBoard();
        $this->setAccountPlan($this->defaultAccountId(), 'lite');
        $this->seedAsset($boardId, BoardAssetProcessor::VARIANT_OG, str_repeat('b', 32));
        $this->seedAsset($boardId, BoardAssetProcessor::VARIANT_FAVICON, str_repeat('c', 32));

        $html = (string) $this->render('/demo');

        self::assertStringNotContainsString('og:image', $html);
        self::assertStringNotContainsString('twitter:image', $html);
        self::assertStringNotContainsString('sizes="64x64"', $html);
        self::assertStringContainsString('href="/favicon.svg"', $html);
        self::assertStringContainsString('href="/apple-touch-icon.png"', $html);
        self::assertStringContainsString('<meta name="twitter:card" content="summary">', $html);
        self::assertStringContainsString('<title>Demo Board · Votepit</title>', $html);
    }

    public function test_private_board_gets_the_plain_shell(): void
    {
        $this->insertBoard('demo', ['visibility' => 'private', 'name' => 'Secret']);

        self::assertNull($this->render('/demo'));
    }

    public function test_non_board_paths_get_no_board_meta_and_stay_out_of_the_index(): void
    {
        foreach (['/login/oauth/github/start', '/Demo%20Board'] as $path) {
            $html = $this->respond($path)->html;
            self::assertStringContainsString('<meta name="robots" content="noindex">', $html, $path);
            self::assertStringContainsString('<title>Votepit</title>', $html, $path);
        }
        self::assertNull($this->render('/demo/a/b/c'));
    }

    public function test_public_entry_pages_stay_indexable_and_app_pages_get_noindex(): void
    {
        foreach (['/', '/signup', '/signup/', '/discover'] as $path) {
            self::assertSame(self::SHELL, BoardShell::plainShell(self::SHELL, $path), $path);
        }
        foreach (['/login', '/admin/boards', '/profile', '/inbox', '/operator', '/acme/admin/boards', '/private-board'] as $path) {
            $html = BoardShell::plainShell(self::SHELL, $path);
            self::assertSame(1, substr_count($html, '<meta name="robots" content="noindex">'), $path);
            self::assertStringNotContainsString('rel="canonical"', $html, $path);
        }
    }

    public function test_public_board_pages_are_their_own_canonical(): void
    {
        $boardId = $this->seedPublicBoard();
        $ideaId  = $this->seedIdea($boardId, $this->insertUser());

        foreach (['/demo', '/demo/roadmap', '/demo/impressum', '/demo/idea/' . $ideaId] as $path) {
            $html = (string) $this->render($path);
            self::assertStringContainsString('<link rel="canonical" href="http://localhost:8000' . $path . '">', $html, $path);
            self::assertStringNotContainsString('noindex', $html, $path);
        }
    }

    public function test_idea_pages_without_a_public_idea_get_noindex_instead_of_a_canonical(): void
    {
        $boardId  = $this->seedPublicBoard();
        $authorId = $this->insertUser();
        $deleted  = $this->seedIdea($boardId, $authorId, 'Gone', ['deleted_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s')]);
        $foreign  = $this->seedIdea($this->insertBoard('other', ['visibility' => 'public']), $authorId, 'Elsewhere');

        foreach (['/demo/idea/999999', '/demo/idea/' . $deleted, '/demo/idea/' . $foreign] as $path) {
            $html = (string) $this->render($path);
            self::assertStringContainsString('<title>Demo Board · Votepit</title>', $html, $path);
            self::assertStringContainsString('<meta name="robots" content="noindex">', $html, $path);
            self::assertStringNotContainsString('rel="canonical"', $html, $path);
        }
    }

    public function test_board_forms_get_noindex_instead_of_a_canonical(): void
    {
        $this->seedPublicBoard();

        foreach (['/demo/submit', '/demo/idea/12/edit'] as $path) {
            $html = (string) $this->render($path);
            self::assertStringContainsString('<meta name="robots" content="noindex">', $html, $path);
            self::assertStringNotContainsString('rel="canonical"', $html, $path);
        }
    }

    public function test_cloud_board_canonical_carries_the_account_segment(): void
    {
        $this->seedPublicBoard();
        $slug = $this->accountSlug($this->defaultAccountId());

        $html = (string) BoardShell::render(self::SHELL, '/' . $slug . '/demo/roadmap', $this->cloudConfig(), $this->conn, self::syntheticPlanPolicy());

        self::assertStringContainsString('<link rel="canonical" href="http://localhost:8000/' . $slug . '/demo/roadmap">', $html);
    }

    public function test_unknown_or_private_board_shells_get_noindex_through_the_front_controller_path(): void
    {
        self::assertNull($this->render('/nope'));
        self::assertStringContainsString('noindex', BoardShell::plainShell(self::SHELL, '/nope'));
    }

    public function test_fails_open_to_the_plain_shell_when_the_config_is_unusable(): void
    {
        $response = BoardShell::forRequest(self::SHELL, '/demo', '/nonexistent/config.php');

        self::assertSame(self::SHELL, $response->html);
        self::assertSame(200, $response->status);
    }

    public function test_fails_open_with_200_when_the_config_file_is_invalid(): void
    {
        $response = BoardShell::forRequest(self::SHELL, '/nope', $this->writeConfig(['db' => ['name' => '']]));

        self::assertSame(self::SHELL, $response->html);
        self::assertSame(200, $response->status);
    }

    public function test_fails_open_with_200_when_the_database_is_unreachable(): void
    {
        // Refused connection on a closed port: the lookup throws, so the shell must not turn into a 404.
        $config   = $this->testConfigArray(['db' => ['host' => '127.0.0.1', 'port' => 1, 'name' => 'votepit']]);
        $response = BoardShell::forRequest(self::SHELL, '/nope', $this->writeConfig($config));

        self::assertSame(self::SHELL, $response->html);
        self::assertSame(200, $response->status);
    }

    // -------------------------------------------------------------------------
    // HTTP status of the shell (Search Console soft 404s)
    // -------------------------------------------------------------------------

    private function respond(string $path, ?\Votepit\Config $config = null): \Votepit\Http\Support\ShellResponse
    {
        return BoardShell::respond(self::SHELL, $path, $config ?? $this->testConfig(), $this->conn, self::syntheticPlanPolicy());
    }

    /**
     * @param array<string, mixed> $config
     */
    private function writeConfig(array $config): string
    {
        $file = tempnam(sys_get_temp_dir(), 'votepit-shell-config-');
        self::assertIsString($file);
        file_put_contents($file, '<?php return ' . var_export($config, true) . ';');
        $this->configFiles[] = $file;

        return $file;
    }

    protected function tearDown(): void
    {
        foreach ($this->configFiles as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    public function test_account_named_like_a_system_route_keeps_its_public_board(): void
    {
        $accountId = $this->insertAccount(['plan' => 'pro', 'slug' => 'faq']);
        $this->insertBoard('demo', ['account_id' => $accountId, 'visibility' => 'public', 'name' => 'FAQ Board']);
        $config = $this->cloudConfig();

        $board = $this->respond('/faq/demo', $config);
        self::assertSame(200, $board->status);
        self::assertStringContainsString('<link rel="canonical" href="http://localhost:8000/faq/demo">', $board->html);
        self::assertSame(200, $this->respond('/faq', $config)->status);
    }

    public function test_board_named_like_a_system_route_keeps_its_public_board_in_self_host(): void
    {
        $this->insertBoard('faq', ['visibility' => 'public', 'name' => 'FAQ Board']);

        $response = $this->respond('/faq');
        self::assertSame(200, $response->status);
        self::assertStringContainsString('<link rel="canonical" href="http://localhost:8000/faq">', $response->html);
    }

    public function test_system_route_without_an_account_or_board_of_that_slug_answers_200_noindex(): void
    {
        $this->seedPublicBoard();

        foreach ([$this->testConfig(), $this->cloudConfig()] as $config) {
            $response = $this->respond('/faq', $config);

            self::assertSame(200, $response->status);
            self::assertStringContainsString('<meta name="robots" content="noindex">', $response->html);
        }
    }

    public function test_unknown_slug_that_is_no_system_route_answers_404_in_both_modes(): void
    {
        $this->seedPublicBoard();

        self::assertSame(404, $this->respond('/zzz-unknown')->status);
        self::assertSame(404, $this->respond('/zzz-unknown', $this->cloudConfig())->status);
    }

    public function test_unknown_self_host_board_answers_404_with_the_noindex_shell(): void
    {
        $response = $this->respond('/nope');

        self::assertSame(404, $response->status);
        self::assertStringContainsString('<meta name="robots" content="noindex">', $response->html);
        self::assertStringNotContainsString('rel="canonical"', $response->html);
    }

    public function test_public_board_pages_and_existing_ideas_answer_200(): void
    {
        $boardId = $this->seedPublicBoard();
        $ideaId  = $this->seedIdea($boardId, $this->insertUser());

        foreach (['/demo', '/demo/roadmap', '/demo/impressum', '/demo/submit', '/demo/idea/' . $ideaId, '/demo/idea/' . $ideaId . '/edit'] as $path) {
            self::assertSame(200, $this->respond($path)->status, $path);
        }
    }

    public function test_private_board_answers_404_like_the_public_board_api(): void
    {
        $this->insertBoard('secret', ['visibility' => 'private', 'name' => 'Secret']);

        $response = $this->respond('/secret');

        self::assertSame(404, $response->status);
        self::assertStringContainsString('<meta name="robots" content="noindex">', $response->html);
        self::assertStringNotContainsString('Secret', $response->html, 'the private board name must not leak');
    }

    public function test_idea_paths_answer_404_unless_the_idea_is_publicly_readable(): void
    {
        $boardId  = $this->seedPublicBoard();
        $authorId = $this->insertUser();
        $target   = $this->seedIdea($boardId, $authorId, 'Target');
        $deleted  = $this->seedIdea($boardId, $authorId, 'Gone', ['deleted_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s')]);
        $merged   = $this->seedIdea($boardId, $authorId, 'Merged', ['merged_into_id' => $target]);
        $pending  = $this->seedIdea($boardId, $authorId, 'Pending', ['moderation_state' => 'pending']);
        $foreign  = $this->seedIdea($this->insertBoard('other', ['visibility' => 'public']), $authorId, 'Elsewhere');

        foreach (['/demo/idea/999999', '/demo/idea/abc', "/demo/idea/{$deleted}", "/demo/idea/{$merged}", "/demo/idea/{$pending}", "/demo/idea/{$foreign}", "/demo/idea/{$deleted}/edit"] as $path) {
            self::assertSame(404, $this->respond($path)->status, $path);
        }
        self::assertSame(200, $this->respond('/demo/idea/' . $target)->status);
    }

    public function test_system_routes_and_public_entry_pages_keep_status_200(): void
    {
        $this->seedPublicBoard();

        foreach (['/', '/signup', '/discover', '/login', '/admin/boards', '/profile', '/operator', '/password/reset/request', '/login/oauth/github/start', '/members/7/profile'] as $path) {
            self::assertSame(200, $this->respond($path)->status, $path);
        }
    }

    public function test_cloud_system_routes_and_known_accounts_answer_200(): void
    {
        $this->seedPublicBoard();
        $slug   = $this->accountSlug($this->defaultAccountId());
        $config = $this->cloudConfig();

        foreach (['/profile', '/discover', '/login', '/' . $slug, '/' . $slug . '/admin/boards', '/' . $slug . '/members/7/profile', '/' . $slug . '/demo'] as $path) {
            self::assertSame(200, $this->respond($path, $config)->status, $path);
        }
    }

    public function test_cloud_unknown_account_answers_404(): void
    {
        $this->seedPublicBoard();
        $config = $this->cloudConfig();

        self::assertSame(404, $this->respond('/nope', $config)->status);
        self::assertSame(404, $this->respond('/nope/demo', $config)->status);
        self::assertSame(404, $this->respond('/nope/admin/boards', $config)->status);
    }

    public function test_cloud_board_of_another_account_answers_404_under_the_wrong_account(): void
    {
        $this->seedPublicBoard();
        $ownSlug      = $this->accountSlug($this->defaultAccountId());
        $otherAccount = $this->insertAccount(['plan' => 'pro', 'slug' => 'other-tenant']);
        $this->insertBoard('elsewhere', ['account_id' => $otherAccount, 'visibility' => 'public', 'name' => 'Elsewhere']);
        $config = $this->cloudConfig();

        self::assertSame(404, $this->respond('/' . $ownSlug . '/elsewhere', $config)->status);
        self::assertSame(404, $this->respond('/other-tenant/demo', $config)->status);
        self::assertSame(200, $this->respond('/other-tenant/elsewhere', $config)->status);
        self::assertSame(200, $this->respond('/' . $ownSlug . '/demo', $config)->status);
    }

    public function test_private_board_of_a_known_account_answers_404_in_cloud(): void
    {
        $ownSlug = $this->accountSlug($this->defaultAccountId());
        $this->insertBoard('secret', ['visibility' => 'private', 'name' => 'Secret']);

        self::assertSame(404, $this->respond('/' . $ownSlug . '/secret', $this->cloudConfig())->status);
    }

    public function test_unknown_board_gets_the_plain_shell(): void
    {
        self::assertNull($this->render('/nope'));
    }

    public function test_unrestricted_policy_is_available_for_self_host_installs(): void
    {
        $this->seedPublicBoard();
        $html = BoardShell::render(self::SHELL, '/demo', $this->testConfig(), $this->conn, new UnrestrictedPlanPolicy());

        self::assertIsString($html);
        self::assertStringContainsString('<title>Demo Board · Votepit</title>', $html);
    }

    // -------------------------------------------------------------------------
    // Member profile reached from a board (owner 27b): icon + share image only
    // -------------------------------------------------------------------------

    private function renderProfile(string $path, ?string $board, ?\Votepit\Config $config = null): ?string
    {
        return BoardShell::render(self::SHELL, $path, $config ?? $this->testConfig(), $this->conn, self::syntheticPlanPolicy(), $board);
    }

    private function cloudConfig(): \Votepit\Config
    {
        return \Votepit\Config::fromArray($this->testConfigArray(['routing_mode' => 'cloud']));
    }

    private function accountSlug(int $accountId): string
    {
        return (string) $this->conn->fetchOne('SELECT slug FROM accounts WHERE id = ?', [$accountId]);
    }

    public function test_profile_with_a_public_board_context_gets_the_board_icon_and_share_image(): void
    {
        $boardId = $this->seedPublicBoard();
        $this->setAccountPlan($this->defaultAccountId(), 'pro');
        $og  = str_repeat('b', 32);
        $fav = str_repeat('c', 32);
        $this->seedAsset($boardId, BoardAssetProcessor::VARIANT_OG, $og);
        $this->seedAsset($boardId, BoardAssetProcessor::VARIANT_FAVICON, $fav);

        $html = (string) $this->renderProfile('/members/7/profile', 'demo');

        self::assertStringContainsString('<meta property="og:image" content="http://localhost:8000/demo/assets/' . $og . '">', $html);
        self::assertStringContainsString('<meta name="twitter:card" content="summary_large_image">', $html);
        self::assertStringContainsString('<link rel="icon" type="image/png" sizes="64x64" href="http://localhost:8000/demo/assets/' . $fav . '">', $html);
        self::assertStringNotContainsString('href="/favicon.svg"', $html, 'the board icon replaces the static icons');
        self::assertStringContainsString('<title>Votepit</title>', $html, 'a profile keeps its own title, the board name is not put in it');
        self::assertStringNotContainsString('og:site_name', $html);
        self::assertStringContainsString('<meta name="robots" content="noindex">', $html, 'member profiles stay out of the index');
        self::assertStringNotContainsString('rel="canonical"', $html);
    }

    public function test_profile_without_a_board_context_keeps_the_votepit_shell(): void
    {
        $boardId = $this->seedPublicBoard();
        $this->setAccountPlan($this->defaultAccountId(), 'pro');
        $this->seedAsset($boardId, BoardAssetProcessor::VARIANT_FAVICON, str_repeat('c', 32));

        self::assertNull($this->renderProfile('/members/7/profile', null));
    }

    public function test_profile_with_an_unknown_board_context_keeps_the_votepit_shell(): void
    {
        $boardId = $this->seedPublicBoard();
        $this->setAccountPlan($this->defaultAccountId(), 'pro');
        $this->seedAsset($boardId, BoardAssetProcessor::VARIANT_FAVICON, str_repeat('c', 32));

        self::assertNull($this->renderProfile('/members/7/profile', 'nope'));
    }

    public function test_profile_with_a_private_board_context_keeps_the_votepit_shell(): void
    {
        $boardId = $this->insertBoard('secret', ['visibility' => 'private', 'name' => 'Secret']);
        $this->setAccountPlan($this->defaultAccountId(), 'pro');
        $this->seedAsset($boardId, BoardAssetProcessor::VARIANT_OG, str_repeat('b', 32));
        $this->seedAsset($boardId, BoardAssetProcessor::VARIANT_FAVICON, str_repeat('c', 32));

        $html = $this->renderProfile('/members/7/profile', 'secret');

        self::assertNull($html, 'a private board must not leak its icon or share image into an anonymous shell');
    }

    public function test_profile_with_a_board_context_on_a_plan_without_the_gate_keeps_the_votepit_shell(): void
    {
        $boardId = $this->seedPublicBoard();
        $this->setAccountPlan($this->defaultAccountId(), 'free');
        $this->seedAsset($boardId, BoardAssetProcessor::VARIANT_OG, str_repeat('b', 32));
        $this->seedAsset($boardId, BoardAssetProcessor::VARIANT_FAVICON, str_repeat('c', 32));

        self::assertNull($this->renderProfile('/members/7/profile', 'demo'));
    }

    public function test_profile_with_a_malformed_board_context_keeps_the_votepit_shell(): void
    {
        $boardId = $this->seedPublicBoard();
        $this->setAccountPlan($this->defaultAccountId(), 'pro');
        $this->seedAsset($boardId, BoardAssetProcessor::VARIANT_FAVICON, str_repeat('c', 32));

        self::assertNull($this->renderProfile('/members/7/profile', '../demo'));
        self::assertNull($this->renderProfile('/members/7/profile', 'Demo'));
        self::assertNull($this->renderProfile('/members/7/profile', '<script>'));
    }

    public function test_profile_board_context_of_another_account_gets_the_votepit_shell(): void
    {
        // Cloud: the path names account A, the board slug exists only in account B.
        $ownAccount   = $this->defaultAccountId();
        $ownSlug      = $this->accountSlug($ownAccount);
        $this->setAccountPlan($ownAccount, 'pro');
        $ownBoardId   = $this->seedPublicBoard();
        $this->seedAsset($ownBoardId, BoardAssetProcessor::VARIANT_FAVICON, str_repeat('d', 32));
        $otherAccount = $this->insertAccount(['plan' => 'pro', 'slug' => 'other-tenant']);
        $foreignId    = $this->insertBoard('elsewhere', ['account_id' => $otherAccount, 'name' => 'Elsewhere']);
        $this->seedAsset($foreignId, BoardAssetProcessor::VARIANT_OG, str_repeat('b', 32));
        $this->seedAsset($foreignId, BoardAssetProcessor::VARIANT_FAVICON, str_repeat('c', 32));
        $config = $this->cloudConfig();

        // A board of account B named in account A's path: not found, nothing added.
        self::assertNull($this->renderProfile('/' . $ownSlug . '/members/7/profile', 'elsewhere', $config));
        // Account B's path naming account A's board ('demo' exists only in A): not found.
        self::assertNull($this->renderProfile('/other-tenant/members/7/profile', 'demo', $config));
    }

    public function test_profile_board_context_is_served_from_the_path_account_in_cloud(): void
    {
        $ownAccount = $this->defaultAccountId();
        $ownSlug    = $this->accountSlug($ownAccount);
        $this->setAccountPlan($ownAccount, 'pro');
        $boardId = $this->seedPublicBoard();
        $fav     = str_repeat('c', 32);
        $this->seedAsset($boardId, BoardAssetProcessor::VARIANT_FAVICON, $fav);

        $html = (string) $this->renderProfile('/' . $ownSlug . '/members/7/profile', 'demo', $this->cloudConfig());

        self::assertStringContainsString('href="http://localhost:8000/' . $ownSlug . '/demo/assets/' . $fav . '"', $html);
        self::assertNull($this->renderProfile('/no-such-account/members/7/profile', 'demo', $this->cloudConfig()));
    }
}
