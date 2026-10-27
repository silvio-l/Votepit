<?php

declare(strict_types=1);

namespace Votepit\Tests\Support;

use Doctrine\DBAL\Connection;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Votepit\Cron\CronJobDefinition;
use Votepit\Domain\PlanPolicy;
use Votepit\Extension\AccountDeletionPrecondition;
use Votepit\Extension\AppExtension;
use Votepit\Extension\BoardComplianceEnforcer;
use Votepit\Extension\BoardOperatorAcknowledgmentGate;
use Votepit\Extension\CronJobProvider;
use Votepit\Extension\DeletionBlocked;
use Votepit\Extension\DuplicateConfirmer;
use Votepit\Extension\ExtensionContext;
use Votepit\Extension\TagSuggester;
use Votepit\Extension\TermsAcceptanceGate;
use Votepit\Http\Middleware\AuthZMiddleware;

/**
 * Configurable AppExtension fixture for core's extension-point tests.
 *
 * Options (all optional):
 *   - plan_policy:      PlanPolicy instance to contribute
 *   - csrf_exemptions:  array<string path, string header>
 *   - features:         array<string, mixed> merged into /api/bootstrap features
 *   - block_deletion:   DeletionBlocked instance the precondition returns
 *   - terms_gate:       TermsAcceptanceGate instance contributed, if any
 *   - board_ack_gate:   BoardOperatorAcknowledgmentGate instance contributed, if any
 *   - compliance_enforcer: BoardComplianceEnforcer instance contributed, if any
 *   - duplicate_confirmer: DuplicateConfirmer instance contributed, if any
 *   - tag_suggester:    TagSuggester instance contributed, if any
 *   - routes:           bool — register POST /ext/webhook (global) and
 *                       GET {accountPrefix}/admin/ext (accountOwner)
 *   - response_headers: array<string name, string value> set on every response
 *   - route_middleware: array<string CoreRoute name, list<MiddlewareInterface>>
 *   - migration_streams: array<string tracking table, string migrations dir>
 *   - reassign_user_references: callable(Connection, int userId, int placeholderUserId): void
 *   - session_route:    bool — register POST /ext/session, which signs the
 *                       user given as `user_id` in the body in through
 *                       $ctx->sessionIssuer (exercises the sanctioned path)
 *   - cron_jobs:        list<CronJobDefinition> returned by cronJobs()
 */
final readonly class StubExtension implements AppExtension, CronJobProvider
{
    /** @param array<string, mixed> $options */
    public function __construct(private array $options) {}

    public static function fromOptions(array $options): self
    {
        return new self($options);
    }

    /**
     * Middleware that short-circuits with a fixed status/body WITHOUT
     * running core's handler — the "guard" shape.
     */
    public static function reply(int $status, string $body, string $contentType = 'application/json'): MiddlewareInterface
    {
        return new class ($status, $body, $contentType) implements MiddlewareInterface {
            public function __construct(private readonly int $status, private readonly string $body, private readonly string $contentType) {}

            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                $response = (new ResponseFactory())->createResponse($this->status)->withHeader('Content-Type', $this->contentType);
                $response->getBody()->write($this->body);

                return $response;
            }
        };
    }

    public function planPolicy(): ?PlanPolicy
    {
        $policy = $this->options['plan_policy'] ?? null;

        return $policy instanceof PlanPolicy ? $policy : null;
    }

    public function csrfExemptions(): array
    {
        return (array) ($this->options['csrf_exemptions'] ?? []);
    }

    public function accountDeletionPrecondition(ExtensionContext $ctx): ?AccountDeletionPrecondition
    {
        $blocked = $this->options['block_deletion'] ?? null;
        if (!$blocked instanceof DeletionBlocked) {
            return null;
        }

        return new class ($blocked) implements AccountDeletionPrecondition {
            public function __construct(private readonly DeletionBlocked $blocked) {}

            public function beforeScheduling(array $account): DeletionBlocked
            {
                return $this->blocked;
            }
        };
    }

    public function termsAcceptanceGate(Connection $conn): ?TermsAcceptanceGate
    {
        $gate = $this->options['terms_gate'] ?? null;

        return $gate instanceof TermsAcceptanceGate ? $gate : null;
    }

    public function boardOperatorAcknowledgmentGate(ExtensionContext $ctx): ?BoardOperatorAcknowledgmentGate
    {
        $gate = $this->options['board_ack_gate'] ?? null;

        return $gate instanceof BoardOperatorAcknowledgmentGate ? $gate : null;
    }

    public function boardComplianceEnforcer(ExtensionContext $ctx): ?BoardComplianceEnforcer
    {
        $enforcer = $this->options['compliance_enforcer'] ?? null;

        return $enforcer instanceof BoardComplianceEnforcer ? $enforcer : null;
    }

    public function duplicateConfirmer(ExtensionContext $ctx): ?DuplicateConfirmer
    {
        $confirmer = $this->options['duplicate_confirmer'] ?? null;

        return $confirmer instanceof DuplicateConfirmer ? $confirmer : null;
    }

    public function uploadTermsVersion(): ?string
    {
        $version = $this->options['upload_terms_version'] ?? null;

        return is_string($version) ? $version : null;
    }

    public function tagSuggester(ExtensionContext $ctx): ?TagSuggester
    {
        $suggester = $this->options['tag_suggester'] ?? null;

        return $suggester instanceof TagSuggester ? $suggester : null;
    }

    public function bootstrapFeatures(): array
    {
        return (array) ($this->options['features'] ?? []);
    }

    public function cronJobs(string $coreRoot): array
    {
        $jobs = [];
        foreach ((array) ($this->options['cron_jobs'] ?? []) as $job) {
            if ($job instanceof CronJobDefinition) {
                $jobs[] = $job;
            }
        }

        return $jobs;
    }

    public function responseHeaders(): array
    {
        return (array) ($this->options['response_headers'] ?? []);
    }

    public function routeMiddleware(ExtensionContext $ctx): array
    {
        return (array) ($this->options['route_middleware'] ?? []);
    }

    public function migrationStreams(): array
    {
        return (array) ($this->options['migration_streams'] ?? []);
    }

    public function reassignUserReferences(Connection $conn, int $userId, int $placeholderUserId): void
    {
        $hook = $this->options['reassign_user_references'] ?? null;
        if (is_callable($hook)) {
            $hook($conn, $userId, $placeholderUserId);
        }
    }

    public function register(ExtensionContext $ctx): void
    {
        $json = static function (ResponseInterface $response, array $payload): ResponseInterface {
            $response->getBody()->write((string) json_encode($payload));
            return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
        };

        if ((bool) ($this->options['session_route'] ?? false)) {
            $ctx->app->post('/ext/session', static function (ServerRequestInterface $request, ResponseInterface $response) use ($ctx): ResponseInterface {
                $parsed = $request->getParsedBody();
                $userId = is_array($parsed) ? (int) ($parsed['user_id'] ?? 0) : 0;
                $user   = $ctx->users->findById($userId);
                if ($user === null) {
                    return $response->withStatus(404);
                }

                return $ctx->sessionIssuer->issue($response, $userId, (int) ($user['token_version'] ?? 0), '/');
            })->add(AuthZMiddleware::anon($ctx->responseFactory));
        }

        if (!(bool) ($this->options['routes'] ?? false)) {
            return;
        }

        $ctx->app->post('/ext/webhook', static fn (ServerRequestInterface $request, ResponseInterface $response): ResponseInterface => $json($response, ['ok' => true, 'webhook' => true]));

        $ctx->app->get($ctx->accountPrefix . '/admin/ext', static fn (ServerRequestInterface $request, ResponseInterface $response): ResponseInterface => $json($response, ['ok' => true, 'prefix' => $ctx->accountPrefix]))
            ->add(AuthZMiddleware::accountOwner($ctx->responseFactory, $ctx->accountMembers));
    }
}
