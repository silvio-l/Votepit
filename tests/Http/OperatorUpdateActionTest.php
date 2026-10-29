<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Votepit\Config;
use Votepit\Http\Action\OperatorUpdateAction;
use Votepit\Tests\Support\IntegrationTestCase;
use Votepit\Tests\Support\StubExtension;
use Votepit\Update\CommunityStateStore;

/**
 * GET /operator/update reads only the stored check result and says whether an
 * update is available; it never calls out. Authz is covered by the route snapshot.
 */
final class OperatorUpdateActionTest extends IntegrationTestCase
{
    /** @param array<class-string, array<string, mixed>> $extensions */
    private function action(string $routingMode = 'self-host', bool $updateCheck = true, array $extensions = []): OperatorUpdateAction
    {
        $config = Config::fromArray($this->testConfigArray([
            'routing_mode' => $routingMode,
            'update_check' => $updateCheck,
            'extensions'   => $extensions,
        ]));

        return new OperatorUpdateAction(new CommunityStateStore($this->conn), $config, dirname(__DIR__, 2));
    }

    /** @return array<string, mixed> */
    private function call(OperatorUpdateAction $action): array
    {
        $request  = (new ServerRequestFactory())->createServerRequest('GET', '/operator/update');
        $response = $action($request, new Response());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));

        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_a_newer_stored_release_is_reported_as_available(): void
    {
        $state = new CommunityStateStore($this->conn);
        $state->set(CommunityStateStore::LATEST_VERSION, '999.0.0');
        $state->set(CommunityStateStore::CHECKED_AT, '2026-10-28 06:31:00');

        $body = $this->call($this->action());

        self::assertTrue($body['update_available']);
        self::assertSame('999.0.0', $body['latest_version']);
        self::assertSame('https://github.com/silvio-l/Votepit/releases/tag/v999.0.0', $body['release_url']);
        self::assertSame('2026-10-28 06:31:00', $body['checked_at']);
        self::assertTrue($body['update_check_enabled']);
    }

    public function test_nothing_stored_means_no_update_and_no_release_link(): void
    {
        $body = $this->call($this->action());

        self::assertFalse($body['update_available']);
        self::assertNull($body['latest_version']);
        self::assertNull($body['release_url']);
    }

    public function test_cloud_and_switched_off_checks_report_the_check_as_disabled(): void
    {
        self::assertFalse($this->call($this->action('cloud'))['update_check_enabled']);
        self::assertFalse($this->call($this->action('self-host', false))['update_check_enabled']);
        self::assertFalse($this->call($this->action('self-host', true, [
            StubExtension::class => ['features' => ['product_edition' => 'cloud']],
        ]))['update_check_enabled']);
    }
}
