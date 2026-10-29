<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Domain\TablePlanPolicy;
use Votepit\Security\CsrfService;
use Votepit\Security\SessionService;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Pro branding (migration 0093): admin GET/POST, plan gates, Q19 downgrade
 * (kept, not applied), re-upgrade, cross-tenant and the public read paths.
 */
final class BoardProBrandingActionTest extends IntegrationTestCase
{
    private const APP_KEY = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const FEATURES_PRO = ['typography_presets', 'custom_wording', 'header_links_sections'];

    private function policy(): TablePlanPolicy
    {
        $base = ['board_limit' => null, 'member_limit' => null, 'agent_api' => false, 'visibilities' => ['public', 'unlisted', 'private'], 'branding_fields' => [], 'roadmap_gantt' => false];

        return new TablePlanPolicy([
            'free' => $base + ['features' => []],
            'lite' => $base + ['features' => []],
            'pro'  => $base + ['features' => self::FEATURES_PRO],
        ], 'free', 'pro', ['free', 'lite', 'pro']);
    }

    private function setPlan(string $plan): void
    {
        $this->setAccountPlan($this->defaultAccountId(), $plan);
    }

    private function adminId(): int
    {
        $userId = $this->insertUser('owner-' . bin2hex(random_bytes(4)) . '@example.com');
        $this->insertAccountMember($this->defaultAccountId(), $userId, 'owner');

        return $userId;
    }

    private function sessions(): SessionService
    {
        return new SessionService(self::APP_KEY, 3600, false);
    }

    private function csrf(): CsrfService
    {
        return new CsrfService(self::APP_KEY, 3600, false);
    }

    private function getPro(string $slug, ?int $userId): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/admin/boards/' . $slug . '/branding/pro');

        return $userId === null ? $request : $request->withCookieParams([
            'votepit_sess' => $this->sessions()->sign(['uid' => $userId, 'v' => 0]),
        ]);
    }

    /** @param array<string, mixed> $fields */
    private function postPro(string $slug, int $userId, array $fields): ServerRequestInterface
    {
        $csrf      = $this->csrf();
        $csrfToken = $csrf->generate();
        $fields['_csrf'] = $csrfToken;

        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/admin/boards/' . $slug . '/branding/pro')
            ->withCookieParams([
                'votepit_sess' => $this->sessions()->sign(['uid' => $userId, 'v' => 0]),
                'votepit_csrf' => $csrf->sign($csrfToken),
            ])
            ->withParsedBody($fields);
    }

    private function publicGet(string $path): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('GET', $path);
    }

    /** @return array<string, mixed> */
    private function json(\Psr\Http\Message\ResponseInterface $response): array
    {
        $data = json_decode((string) $response->getBody(), true);

        return is_array($data) ? $data : [];
    }

    /** @return array<string, mixed> */
    private function row(string $slug): array
    {
        $row = $this->conn->fetchAssociative('SELECT * FROM boards WHERE slug = :s', ['s' => $slug]);

        return is_array($row) ? $row : [];
    }

    /** @return array<string, mixed> */
    private function proPayload(): array
    {
        return [
            'typography_preset' => 'mono',
            'shape_style'       => 'square',
            'density'           => 'compact',
            'wording'           => ['en' => ['vote_for' => 'Support', 'vote_against' => 'Concern', 'idea_singular' => 'suggestion', 'idea_plural' => 'suggestions']],
            'header_links'      => [['url' => 'https://example.com/about', 'label' => 'About us']],
            'roadmap_hidden'    => false,
        ];
    }

    // -------------------------------------------------------------------------
    // AuthZ
    // -------------------------------------------------------------------------

    public function test_anon_and_plain_member_are_rejected(): void
    {
        $this->insertBoard('demo');
        $plain = $this->insertUser('plain@example.com');

        $app = $this->createApp(planPolicy: $this->policy());
        self::assertSame(401, $app->handle($this->getPro('demo', null))->getStatusCode());
        self::assertSame(403, $app->handle($this->getPro('demo', $plain))->getStatusCode());
    }

    public function test_admin_get_returns_stored_values_and_entitlements(): void
    {
        $this->insertBoard('demo', ['typography_preset' => 'mono', 'roadmap_hidden' => 1]);
        $this->setPlan('free');

        $response = $this->createApp(planPolicy: $this->policy())->handle($this->getPro('demo', $this->adminId()));
        $data     = $this->json($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('mono', $data['typography_preset']);
        self::assertTrue($data['roadmap_hidden']);
        self::assertFalse($data['entitlements']['features']['typography_presets']['allowed']);
        self::assertSame('pro', $data['entitlements']['features']['typography_presets']['required_plan']);
    }

    // -------------------------------------------------------------------------
    // Gates and validation
    // -------------------------------------------------------------------------

    public function test_pro_plan_saves_all_groups(): void
    {
        $this->insertBoard('demo');
        $this->setPlan('pro');

        $response = $this->createApp(planPolicy: $this->policy())->handle($this->postPro('demo', $this->adminId(), $this->proPayload()));
        self::assertSame(200, $response->getStatusCode());

        $row = $this->row('demo');
        self::assertSame('mono', $row['typography_preset']);
        self::assertSame('square', $row['shape_style']);
        self::assertSame('compact', $row['density']);
        self::assertStringContainsString('Support', (string) $row['wording_json']);
        self::assertStringContainsString('https://example.com/about', (string) $row['header_links_json']);
    }

    public function test_locked_group_rejects_a_changed_value_with_plan_locked_field(): void
    {
        $this->insertBoard('demo');
        $this->setPlan('lite');

        $response = $this->createApp(planPolicy: $this->policy())->handle(
            $this->postPro('demo', $this->adminId(), ['typography_preset' => 'mono']),
        );
        $error = $this->json($response)['error'];

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('plan_locked_field', $error['key']);
        self::assertSame(['typography_preset'], $error['locked_fields']);
        self::assertNull($this->row('demo')['typography_preset']);
    }

    public function test_locked_group_without_changes_is_kept_and_clearing_is_allowed(): void
    {
        $this->insertBoard('demo', ['typography_preset' => 'mono', 'wording_json' => '{"en":{"vote_for":"Support"}}']);
        $this->setPlan('lite');
        $app = $this->createApp(planPolicy: $this->policy());

        // Unchanged or absent values keep the stored ones.
        self::assertSame(200, $app->handle($this->postPro('demo', $this->adminId(), ['typography_preset' => 'mono']))->getStatusCode());
        self::assertSame(200, $app->handle($this->postPro('demo', $this->adminId(), []))->getStatusCode());
        self::assertSame('mono', $this->row('demo')['typography_preset']);

        // Clearing is always allowed.
        self::assertSame(200, $app->handle($this->postPro('demo', $this->adminId(), ['typography_preset' => '']))->getStatusCode());
        self::assertNull($this->row('demo')['typography_preset']);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidPayloads(): iterable
    {
        yield 'javascript link' => [['header_links' => [['url' => 'javascript:alert(1)', 'label' => 'x']]]];
        yield 'markup in wording' => [['wording' => ['en' => ['thank_you' => '<script>x</script>']]]];
        yield 'unknown preset' => [['shape_style' => 'round; color:red']];
    }

    /** @param array<string, mixed> $payload */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidPayloads')]
    public function test_invalid_value_rejects_the_whole_request_and_writes_nothing(array $payload): void
    {
        $this->insertBoard('demo');
        $this->setPlan('pro');

        $response = $this->createApp(planPolicy: $this->policy())->handle(
            $this->postPro('demo', $this->adminId(), $payload + ['typography_preset' => 'mono']),
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('validation_error', $this->json($response)['error']['key']);
        self::assertNull($this->row('demo')['typography_preset']);
    }

    // -------------------------------------------------------------------------
    // Downgrade, re-upgrade and the public read path
    // -------------------------------------------------------------------------

    public function test_downgrade_keeps_values_unapplied_and_upgrade_restores_them(): void
    {
        $this->insertBoard('demo', ['visibility' => 'public']);
        $this->setPlan('pro');
        $app = $this->createApp(planPolicy: $this->policy());
        self::assertSame(200, $app->handle($this->postPro('demo', $this->adminId(), $this->proPayload()))->getStatusCode());

        $this->setPlan('lite');
        $down = $this->json($app->handle($this->publicGet('/demo')))['board']['branding_pro'];
        self::assertSame('default', $down['typography']);
        self::assertSame('rounded', $down['shape']);
        self::assertSame([], $down['wording']);
        self::assertSame([], $down['header_links']);
        self::assertSame('mono', $this->row('demo')['typography_preset'], 'stored value is kept');

        $this->setPlan('pro');
        $up = $this->json($app->handle($this->publicGet('/demo')))['board']['branding_pro'];
        self::assertSame('mono', $up['typography']);
        self::assertSame('square', $up['shape']);
        self::assertSame('compact', $up['density']);
        self::assertSame('Support', $up['wording']['en']['vote_for']);
        self::assertSame('https://example.com/about', $up['header_links'][0]['url']);
    }

    public function test_idea_page_applies_the_owner_wording_only_while_the_plan_includes_it(): void
    {
        $this->insertBoard('demo', ['visibility' => 'public']);
        $this->setPlan('pro');
        $app = $this->createApp(planPolicy: $this->policy());
        self::assertSame(200, $app->handle($this->postPro('demo', $this->adminId(), $this->proPayload()))->getStatusCode());
        $ideaId = $this->seedIdea((int) $this->row('demo')['id'], $this->adminId(), 'Dark mode');

        $pro = $this->json($app->handle($this->publicGet('/demo/ideas/' . $ideaId)))['board']['branding_pro'];
        self::assertSame('Support', $pro['wording']['en']['vote_for']);
        self::assertSame('Concern', $pro['wording']['en']['vote_against']);

        $this->setPlan('lite');
        $lite = $this->json($app->handle($this->publicGet('/demo/ideas/' . $ideaId)))['board']['branding_pro'];
        self::assertSame([], $lite['wording']);
        self::assertSame('default', $lite['typography']);
    }

    public function test_hidden_roadmap_is_404_for_visitors_but_not_for_owners(): void
    {
        $this->insertBoard('demo', ['visibility' => 'public', 'roadmap_hidden' => 1]);
        $this->setPlan('pro');
        $app = $this->createApp(planPolicy: $this->policy());

        self::assertSame(404, $app->handle($this->publicGet('/demo/roadmap'))->getStatusCode());

        $ownerRequest = $this->publicGet('/demo/roadmap')->withCookieParams([
            'votepit_sess' => $this->sessions()->sign(['uid' => $this->adminId(), 'v' => 0]),
        ]);
        self::assertSame(200, $app->handle($ownerRequest)->getStatusCode());
    }

    public function test_roadmap_stays_visible_when_the_plan_does_not_include_section_toggles(): void
    {
        $this->insertBoard('demo', ['visibility' => 'public', 'roadmap_hidden' => 1]);
        $this->setPlan('lite');

        self::assertSame(200, $this->createApp(planPolicy: $this->policy())->handle($this->publicGet('/demo/roadmap'))->getStatusCode());
    }

    public function test_board_of_another_account_is_not_reachable(): void
    {
        $otherAccount = $this->insertAccount(['slug' => 'other', 'name' => 'Other']);
        $this->insertBoard('elsewhere', ['account_id' => $otherAccount]);
        $this->setPlan('pro');

        $app = $this->createApp(planPolicy: $this->policy());
        self::assertSame(404, $app->handle($this->getPro('elsewhere', $this->adminId()))->getStatusCode());
        self::assertSame(404, $app->handle($this->postPro('elsewhere', $this->adminId(), $this->proPayload()))->getStatusCode());
    }

    public function test_frozen_board_rejects_writes(): void
    {
        $this->insertBoard('demo', ['frozen_at' => '2026-10-01 00:00:00']);
        $this->setPlan('pro');

        self::assertSame(423, $this->createApp(planPolicy: $this->policy())->handle($this->postPro('demo', $this->adminId(), $this->proPayload()))->getStatusCode());
    }

    public function test_hostile_stored_json_is_never_exposed(): void
    {
        $this->insertBoard('demo', ['visibility' => 'public', 'header_links_json' => '[{"url":"javascript:alert(1)","label":"x"}]', 'wording_json' => '{"en":{"thank_you":"<b>x</b>"}}']);
        $this->setPlan('pro');

        $branding = $this->json($this->createApp(planPolicy: $this->policy())->handle($this->publicGet('/demo')))['board']['branding_pro'];
        self::assertSame([], $branding['header_links']);
        self::assertSame([], $branding['wording']);
    }
}
