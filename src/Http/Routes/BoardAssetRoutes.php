<?php

declare(strict_types=1);

namespace Votepit\Http\Routes;

use Doctrine\DBAL\Connection;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Psr7\Factory\ResponseFactory;
use Votepit\Config;
use Votepit\Domain\AccountPlanResolver;
use Votepit\Domain\PlanPolicy;
use Votepit\Http\Action\BoardAssetAction;
use Votepit\Http\Action\BoardLinkFooterAction;
use Votepit\Http\Action\UploadTermsAction;
use Votepit\Http\Middleware\AuthNMiddleware;
use Votepit\Http\Middleware\AuthZMiddleware;
use Votepit\Http\Middleware\RateLimitMiddleware;
use Votepit\Logging\AuditLogger;
use Votepit\Logging\AuthzDenialLogger;
use Votepit\Persistence\AccountMemberRepository;
use Votepit\Persistence\AccountRepository;
use Votepit\Persistence\BoardAssetRepository;
use Votepit\Persistence\BoardRepository;
use Votepit\Persistence\UploadTermsConsentRepository;
use Votepit\Security\BoardAssetFileStore;
use Votepit\Security\BoardAssetProcessor;
use Votepit\Security\RateLimiter;

/**
 * Board imagery and link/footer (P-28, P-31 Lite part).
 *
 * Admin routes are account- and board-scoped (AuthZ: accountAdmin, the same
 * owner/admin set as branding). The public image route is anon and serves a
 * public id only for the board named in the URL.
 */
final class BoardAssetRoutes
{
    /**
     * @param App<null> $app
     */
    public static function register(
        App $app,
        Config $config,
        ResponseFactory $responseFactory,
        Connection $conn,
        AuditLogger $audit,
        AuthzDenialLogger $authzDenialLogger,
        string $accountPrefix,
        BoardRepository $boardRepo,
        AccountRepository $accountRepoForPlan,
        PlanPolicy $planPolicy,
        AccountMemberRepository $accountMemberRepo,
        BoardAssetFileStore $files,
        ?string $uploadTermsVersion,
    ): void {
        $uploadTermsConsents = new UploadTermsConsentRepository($conn);
        $accountPlans = new AccountPlanResolver($accountRepoForPlan, $accountMemberRepo, $planPolicy);
        $assetAction = new BoardAssetAction(
            $boardRepo,
            $accountPlans,
            new BoardAssetRepository($conn),
            $files,
            new BoardAssetProcessor(),
            $planPolicy,
            $audit,
            $uploadTermsConsents,
            $uploadTermsVersion,
            $config->boardUploadsEnabled,
        );
        $linkFooterAction = new BoardLinkFooterAction($boardRepo, $accountPlans, $planPolicy, $audit);
        $uploadTermsAction = new UploadTermsAction($boardRepo, $uploadTermsConsents, $audit, $uploadTermsVersion);

        $accountAdmin = static fn (): \Votepit\Http\Middleware\AuthZMiddleware => AuthZMiddleware::accountAdmin($responseFactory, $accountMemberRepo, $authzDenialLogger);

        // GET /admin/boards/{slug}/assets — current imagery, plan flags, limits.
        $app->get($accountPrefix . '/admin/boards/{slug}/assets', $assetAction->list(...))
            ->add($accountAdmin());

        // POST /admin/boards/{slug}/assets/{kind} — upload + re-encode (rate limited).
        $uploadRateLimit = $config->rateLimit('board:asset-upload');
        $app->post($accountPrefix . '/admin/boards/{slug}/assets/{kind:cover|favicon|og|logo|wordmark_light|wordmark}', $assetAction->upload(...))
            ->add(RateLimitMiddleware::perAction(
                new RateLimiter($conn),
                $responseFactory,
                'board:asset-upload',
                $uploadRateLimit['limit'],
                $uploadRateLimit['window'],
                static function (ServerRequestInterface $r): ?string {
                    $user = $r->getAttribute(AuthNMiddleware::ATTR_USER);
                    return is_array($user) ? (string) ($user['id'] ?? '') : null;
                },
            ))
            ->add($accountAdmin());

        // POST /admin/boards/{slug}/assets/terms/accept — upload clause consent (once per account and version).
        $termsRateLimit = $config->rateLimit('board:asset-terms');
        $app->post($accountPrefix . '/admin/boards/{slug}/assets/terms/accept', $uploadTermsAction->accept(...))
            ->add(RateLimitMiddleware::perAction(
                new RateLimiter($conn),
                $responseFactory,
                'board:asset-terms',
                $termsRateLimit['limit'],
                $termsRateLimit['window'],
                static function (ServerRequestInterface $r): ?string {
                    $user = $r->getAttribute(AuthNMiddleware::ATTR_USER);
                    return is_array($user) ? (string) ($user['id'] ?? '') : null;
                },
            ))
            ->add($accountAdmin());

        // POST /admin/boards/{slug}/assets/{kind}/delete — remove (POST, like the other sub-resource deletes).
        $app->post($accountPrefix . '/admin/boards/{slug}/assets/{kind:cover|favicon|og|logo|wordmark_light|wordmark}/delete', $assetAction->delete(...))
            ->add($accountAdmin());

        // PUT /admin/boards/{slug}/link-footer — back link + footer note.
        $app->put($accountPrefix . '/admin/boards/{slug}/link-footer', $linkFooterAction->save(...))
            ->add($accountAdmin());

        // GET /{board}/assets/{publicId} — public image, anon. Board-scoped by the URL.
        $app->get($accountPrefix . '/{board}/assets/{id:[0-9a-f]{32}}', $assetAction->serve(...))
            ->add(AuthZMiddleware::anon($responseFactory));
    }
}
