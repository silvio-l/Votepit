<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use Votepit\Tests\Support\IntegrationTestCase;

/**
 * Keeps documentation/openapi.yaml (hand-maintained Agent API contract) and
 * the registered /api/v1 routes in sync in both directions: every
 * registered method+path is documented, every documented operation exists.
 *
 * No YAML dependency in core — the spec's layout is fixed instead: paths
 * are the 2-space-indented keys under `paths:`, operations the 4-space-
 * indented HTTP-method keys below them. FastRoute placeholders are
 * normalised (`{id:[0-9]+}` → `{id}`) before comparing.
 */
final class OpenApiSpecTest extends IntegrationTestCase
{
    private const SPEC = __DIR__ . '/../../documentation/openapi.yaml';

    private const METHODS = ['get', 'put', 'post', 'delete', 'patch', 'head', 'options'];

    public function test_spec_declares_openapi_3_1(): void
    {
        self::assertMatchesRegularExpression('/^openapi: 3\.1\.\d+$/m', $this->spec());
    }

    public function test_every_api_v1_route_is_documented_and_vice_versa(): void
    {
        $registered = [];
        foreach ($this->createApp()->getRouteCollector()->getRoutes() as $route) {
            $pattern = $route->getPattern();
            if (!str_starts_with($pattern, '/api/v1/')) {
                continue;
            }
            $path = (string) preg_replace('/\{(\w+):[^}]+\}/', '{$1}', $pattern);
            foreach ($route->getMethods() as $method) {
                $registered[] = strtoupper($method) . ' ' . $path;
            }
        }
        sort($registered);

        $documented = $this->documentedOperations();

        self::assertNotSame([], $registered);
        self::assertSame([], array_values(array_diff($registered, $documented)), 'registered /api/v1 routes missing from openapi.yaml');
        self::assertSame([], array_values(array_diff($documented, $registered)), 'openapi.yaml operations without a registered route');
    }

    /** @return list<string> "METHOD /path", sorted */
    private function documentedOperations(): array
    {
        $operations = [];
        $inPaths    = false;
        $path       = null;

        $lines = preg_split('/\R/', $this->spec());
        self::assertIsArray($lines);

        foreach ($lines as $line) {
            if (preg_match('/^(\S[^:]*):/', $line, $m) === 1) {
                $inPaths = $m[1] === 'paths';
                $path    = null;
                continue;
            }
            if (!$inPaths) {
                continue;
            }
            if (preg_match('#^  (/\S*):\s*$#', $line, $m) === 1) {
                $path = $m[1];
                continue;
            }
            if ($path !== null && preg_match('/^    ([a-z]+):\s*$/', $line, $m) === 1 && in_array($m[1], self::METHODS, true)) {
                $operations[] = strtoupper($m[1]) . ' ' . $path;
            }
        }
        sort($operations);

        return $operations;
    }

    private function spec(): string
    {
        $spec = file_get_contents(self::SPEC);
        self::assertIsString($spec);

        return $spec;
    }
}
