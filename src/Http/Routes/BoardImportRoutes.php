<?php

declare(strict_types=1);

namespace Votepit\Http\Routes;

use Doctrine\DBAL\Connection;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Psr7\Factory\ResponseFactory;
use Votepit\Config;
use Votepit\Domain\TitleNormalizer;
use Votepit\Http\Action\BoardImportAction;
use Votepit\Http\Middleware\AuthNMiddleware;
use Votepit\Http\Middleware\AuthZMiddleware;
use Votepit\Http\Middleware\RateLimitMiddleware;
use Votepit\Import\BoardImportService;
use Votepit\Logging\AuditLogger;
use Votepit\Logging\AuthzDenialLogger;
use Votepit\Persistence\AccountMemberRepository;
use Votepit\Persistence\BoardRepository;
use Votepit\Persistence\IdeaImportRepository;
use Votepit\Persistence\IdeaRepository;
use Votepit\Persistence\TagRepository;
use Votepit\Security\RateLimiter;

/**
 * Board import (CSV, Canny, Featurebase exports) under
 * {account}/admin/boards/{slug}/import.
 */
final class BoardImportRoutes
{
    /** @param App<null> $app */
    public static function register(
        App $app,
        Config $config,
        ResponseFactory $responseFactory,
        Connection $conn,
        AuditLogger $audit,
        AuthzDenialLogger $authzDenialLogger,
        string $accountPrefix,
        BoardRepository $boardRepo,
        AccountMemberRepository $accountMemberRepo,
        IdeaRepository $ideaRepo,
        TagRepository $tagRepo,
        TitleNormalizer $normalizer,
    ): void {
        // POST /admin/boards/{slug}/import — dry-run preview (default) or
        // apply an import. AuthZ: accountAdmin (bulk-creates content, same
        // level as the other board settings); CSRF global; per-user rate
        // limit board:import (dry runs count too — every request parses an
        // upload of up to CsvTable::MAX_BYTES). Board-scoped via
        // findBySlugForAccount() in the action (foreign board → 404).
        $importLimit = $config->rateLimit('board:import');
        $service     = new BoardImportService($conn, $ideaRepo, $tagRepo, new IdeaImportRepository($conn), $normalizer);

        $app->post($accountPrefix . '/admin/boards/{slug}/import', new BoardImportAction($boardRepo, $service, $audit))
            ->add(AuthZMiddleware::accountAdmin($responseFactory, $accountMemberRepo, $authzDenialLogger))
            ->add(RateLimitMiddleware::perAction(
                new RateLimiter($conn),
                $responseFactory,
                'board:import',
                $importLimit['limit'],
                $importLimit['window'],
                static function (ServerRequestInterface $r): ?string {
                    $user = $r->getAttribute(AuthNMiddleware::ATTR_USER);
                    return is_array($user) ? (string) ($user['id'] ?? '') : null;
                },
            ));
    }
}
