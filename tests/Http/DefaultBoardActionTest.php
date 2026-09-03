<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Integration tests for GET /api/board/default (2026-08-31 — fixes BUG #1 from the
 * E2E frontend test: without this endpoint, the SPA root route `/` had no way to
 * resolve the default board and stayed permanently stuck on "Loading…" — this is,
 * among others, exactly the landing path right after signing in).
 */
final class DefaultBoardActionTest extends IntegrationTestCase
{
    public function test_returns_the_default_board_slug(): void
    {
        $this->insertBoard('product-ideas', ['is_default' => 1]);
        $this->insertBoard('mobile-app', ['is_default' => 0]);

        $response = $this->createApp()->handle(
            (new ServerRequestFactory())->createServerRequest('GET', '/api/board/default'),
        );

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('product-ideas', $data['slug'] ?? null);
    }

    public function test_falls_back_to_oldest_public_board_when_no_default_flag_set(): void
    {
        $this->insertBoard('older-board', ['is_default' => 0, 'created_at' => '2025-01-01 10:00:00']);
        $this->insertBoard('newer-board', ['is_default' => 0, 'created_at' => '2025-06-01 10:00:00']);

        $response = $this->createApp()->handle(
            (new ServerRequestFactory())->createServerRequest('GET', '/api/board/default'),
        );

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('older-board', $data['slug'] ?? null);
    }

    public function test_private_board_is_ineligible_for_anonymous_visitor(): void
    {
        $this->insertBoard('private-board', ['is_default' => 1, 'visibility' => 'private']);

        $response = $this->createApp()->handle(
            (new ServerRequestFactory())->createServerRequest('GET', '/api/board/default'),
        );

        self::assertSame(404, $response->getStatusCode());
    }

    public function test_no_public_board_returns_404_no_board(): void
    {
        $response = $this->createApp()->handle(
            (new ServerRequestFactory())->createServerRequest('GET', '/api/board/default'),
        );

        self::assertSame(404, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('no_board', $data['error']['key'] ?? null);
    }
}
