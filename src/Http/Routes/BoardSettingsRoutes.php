<?php

declare(strict_types=1);

namespace Votepit\Http\Routes;

use Doctrine\DBAL\Connection;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Psr7\Factory\ResponseFactory;
use Votepit\Cleanup\BoardAssetCleanup;
use Votepit\Config;
use Votepit\Domain\AccountPlanResolver;
use Votepit\Domain\ContentModerationService;
use Votepit\Domain\PlanPolicy;
use Votepit\Extension\BoardComplianceEnforcer;
use Votepit\Extension\BoardOperatorAcknowledgmentGate;
use Votepit\Extension\DuplicateConfirmer;
use Votepit\Http\Action\BoardBrandingAction;
use Votepit\Http\Action\BoardDeleteAction;
use Votepit\Http\Action\BoardModerationAction;
use Votepit\Http\Action\BoardOperatorAcknowledgmentAction;
use Votepit\Http\Action\BoardPhasesAdminAction;
use Votepit\Http\Action\BoardProBrandingAction;
use Votepit\Http\Action\BoardRenameAction;
use Votepit\Http\Action\BoardResultsExportAction;
use Votepit\Http\Action\BoardStatusesAdminAction;
use Votepit\Http\Action\UserBlockAction;
use Votepit\Http\Middleware\AuthNMiddleware;
use Votepit\Http\Middleware\AuthZMiddleware;
use Votepit\Http\Middleware\RateLimitMiddleware;
use Votepit\Http\Support\AnonymousSubmissionGate;
use Votepit\Logging\AuditLogger;
use Votepit\Logging\AuthzDenialLogger;
use Votepit\Persistence\AccountMemberRepository;
use Votepit\Persistence\AccountRepository;
use Votepit\Persistence\BlockRepository;
use Votepit\Persistence\BoardRepository;
use Votepit\Persistence\BoardStatusRepository;
use Votepit\Persistence\IdeaRepository;
use Votepit\Persistence\ModerationConfigRepository;
use Votepit\Persistence\TagRepository;
use Votepit\Persistence\UserRepository;
use Votepit\Security\RateLimiter;
use Votepit\Support\Clock;

/**
 * Per-board admin settings under {account}/admin/boards/{slug}: branding,
 * operator acknowledgment, rename, delete, moderation settings and the
 * account-wide user block.
 */
final class BoardSettingsRoutes
{
    /**
     * Board settings: branding, operator acknowledgment, rename, delete,
     * moderation, user blocking.
     *
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
        UserRepository $userRepo,
        ModerationConfigRepository $modConfigRepo,
        ContentModerationService $moderation,
        BoardOperatorAcknowledgmentGate $boardOperatorAckGate,
        BoardComplianceEnforcer $boardComplianceEnforcer,
        BoardStatusRepository $boardStatusRepo,
        Clock $clock,
        AnonymousSubmissionGate $anonymousSubmissionGate,
        ?DuplicateConfirmer $duplicateConfirmer = null,
        bool $billingEnabled = false,
        ?BoardAssetCleanup $boardAssetCleanup = null,
    ): void {
        // GET/POST /admin/boards/{slug}/branding — read/save the branding settings page
        // (AuthZ: accountAdmin, board-scoped, CSRF globally enforced for POST).
        // Every value is strictly validated BEFORE saving; invalid → null → default theme
        // (no raw value ever ends up in the DB/CSS).
        $accountPlans = new AccountPlanResolver($accountRepoForPlan, $accountMemberRepo, $planPolicy);
        $brandingAction = new BoardBrandingAction($boardRepo, $accountRepoForPlan, $accountPlans, $planPolicy, $audit, $boardComplianceEnforcer, $duplicateConfirmer, $accountMemberRepo, $billingEnabled, $config->voteReasonsEnabled);

        $app->get($accountPrefix . '/admin/boards/{slug}/branding', $brandingAction->getBranding(...))
            ->add(AuthZMiddleware::accountAdmin($responseFactory, $accountMemberRepo, $authzDenialLogger));

        $app->post($accountPrefix . '/admin/boards/{slug}/branding', $brandingAction->postBranding(...))
            ->add(AuthZMiddleware::accountAdmin($responseFactory, $accountMemberRepo, $authzDenialLogger));

        // GET/POST /admin/boards/{slug}/branding/pro — Pro branding (migration 0093):
        // typography/shape/density presets, custom wording, header links and the
        // roadmap toggle. Plan-gated per group (PlanPolicy features); accountAdmin AuthZ.
        $proBrandingAction = new BoardProBrandingAction($boardRepo, $accountRepoForPlan, $accountPlans, $planPolicy, $accountMemberRepo, $audit, $billingEnabled);

        $app->get($accountPrefix . '/admin/boards/{slug}/branding/pro', $proBrandingAction->getProBranding(...))
            ->add(AuthZMiddleware::accountAdmin($responseFactory, $accountMemberRepo, $authzDenialLogger));

        $app->post($accountPrefix . '/admin/boards/{slug}/branding/pro', $proBrandingAction->postProBranding(...))
            ->add(AuthZMiddleware::accountAdmin($responseFactory, $accountMemberRepo, $authzDenialLogger));

        // Owner-defined statuses (broaden-use-cases issue 03, migration 0073):
        // list / create / update / delete. AuthZ: accountAdmin (moderators only
        // change an idea's status), board-scoped via findBySlugForAccount,
        // CSRF globally enforced for the writes, per-action rate limit
        // board:statuses. Not plan-gated. See BoardStatusesAdminAction.
        $statusesAction    = new BoardStatusesAdminAction($conn, $boardRepo, $boardStatusRepo, $audit);
        $statusesRateLimit = $config->rateLimit('board:statuses');
        $statusesLimiter   = static fn (): RateLimitMiddleware => RateLimitMiddleware::perAction(
            new RateLimiter($conn),
            $responseFactory,
            'board:statuses',
            $statusesRateLimit['limit'],
            $statusesRateLimit['window'],
            static function (ServerRequestInterface $r): ?string {
                $user = $r->getAttribute(AuthNMiddleware::ATTR_USER);
                return is_array($user) ? (string) ($user['id'] ?? '') : null;
            },
        );

        $app->get($accountPrefix . '/admin/boards/{slug}/statuses', $statusesAction->list(...))
            ->add(AuthZMiddleware::accountAdmin($responseFactory, $accountMemberRepo, $authzDenialLogger));

        $app->post($accountPrefix . '/admin/boards/{slug}/statuses', $statusesAction->create(...))
            ->add(AuthZMiddleware::accountAdmin($responseFactory, $accountMemberRepo, $authzDenialLogger))
            ->add($statusesLimiter());

        $app->put($accountPrefix . '/admin/boards/{slug}/statuses/{key:[a-z0-9_]{1,16}}', $statusesAction->update(...))
            ->add(AuthZMiddleware::accountAdmin($responseFactory, $accountMemberRepo, $authzDenialLogger))
            ->add($statusesLimiter());

        $app->post($accountPrefix . '/admin/boards/{slug}/statuses/{key:[a-z0-9_]{1,16}}/delete', $statusesAction->delete(...))
            ->add(AuthZMiddleware::accountAdmin($responseFactory, $accountMemberRepo, $authzDenialLogger))
            ->add($statusesLimiter());

        // Optional deadlines (broaden-use-cases issue 04, migration 0082):
        // read / replace the submission + voting windows. AuthZ:
        // accountAdmin, board-scoped via findBySlugForAccount, CSRF globally
        // enforced for POST. Not plan-gated (every plan, user decision 3b).
        $phasesAction = new BoardPhasesAdminAction($boardRepo, $boardStatusRepo, $audit, $clock);

        $app->get($accountPrefix . '/admin/boards/{slug}/phases', $phasesAction->get(...))
            ->add(AuthZMiddleware::accountAdmin($responseFactory, $accountMemberRepo, $authzDenialLogger));

        $app->post($accountPrefix . '/admin/boards/{slug}/phases', $phasesAction->post(...))
            ->add(AuthZMiddleware::accountAdmin($responseFactory, $accountMemberRepo, $authzDenialLogger));

        // GET /admin/boards/{slug}/export — board results as CSV
        // (broaden-use-cases issue 07). AuthZ: accountAdmin, board-scoped via
        // findBySlugForAccount, per-user rate limit board:export, audit-
        // logged. Not plan-gated. See BoardResultsExportAction.
        $resultsExportRateLimit = $config->rateLimit('board:export');
        $app->get(
            $accountPrefix . '/admin/boards/{slug}/export',
            (new BoardResultsExportAction($boardRepo, new IdeaRepository($conn), new TagRepository($conn), $audit))->download(...),
        )
            ->add(AuthZMiddleware::accountAdmin($responseFactory, $accountMemberRepo, $authzDenialLogger))
            ->add(RateLimitMiddleware::perAction(
                new RateLimiter($conn),
                $responseFactory,
                'board:export',
                $resultsExportRateLimit['limit'],
                $resultsExportRateLimit['window'],
                static function (ServerRequestInterface $r): ?string {
                    $user = $r->getAttribute(AuthNMiddleware::ATTR_USER);
                    return is_array($user) ? (string) ($user['id'] ?? '') : null;
                },
            ));

        // GET/POST /admin/boards/{slug}/operator-acknowledgment — ongoing
        // counterpart to BoardCreateAction's one-time board-operator
        // acknowledgment check (board-operator-compliance ticket 05
        // follow-up). AuthZ: accountAdmin, board-scoped, CSRF globally
        // enforced for POST. See BoardOperatorAcknowledgmentAction's class
        // doc for why POST is idempotent.
        $operatorAckAction = new BoardOperatorAcknowledgmentAction($boardRepo, $boardOperatorAckGate, $audit, $boardComplianceEnforcer);

        $app->get($accountPrefix . '/admin/boards/{slug}/operator-acknowledgment', $operatorAckAction->getStatus(...))
            ->add(AuthZMiddleware::accountAdmin($responseFactory, $accountMemberRepo, $authzDenialLogger));

        $app->post($accountPrefix . '/admin/boards/{slug}/operator-acknowledgment', $operatorAckAction->acknowledge(...))
            ->add(AuthZMiddleware::accountAdmin($responseFactory, $accountMemberRepo, $authzDenialLogger));

        // PUT /admin/boards/{slug} — renames a board's title and/or slug.
        // AuthZ: accountAdmin, board-scoped, CSRF globally enforced. See
        // BoardRenameAction's class doc for the independent title/slug
        // semantics and the frozen-board/tombstone/collision rules.
        $app->put($accountPrefix . '/admin/boards/{slug}', (new BoardRenameAction($boardRepo, $audit))->rename(...))
            ->add(AuthZMiddleware::accountAdmin($responseFactory, $accountMemberRepo, $authzDenialLogger));

        // POST /admin/boards/{slug}/delete — permanent board deletion.
        // AuthZ: accountOwner (stricter than the accountAdmin routes above —
        // see BoardDeleteAction's class doc for why deletion alone is
        // owner-only), CSRF globally enforced.
        $app->post($accountPrefix . '/admin/boards/{slug}/delete', (new BoardDeleteAction($boardRepo, $audit, $boardAssetCleanup))->delete(...))
            ->add(AuthZMiddleware::accountOwner($responseFactory, $accountMemberRepo, $authzDenialLogger));

        // GET/POST /admin/boards/{slug}/moderation — read/save the moderation settings page
        // (AuthZ: accountAdmin, board-scoped, CSRF globally enforced for POST).
        // GET shows a toggle (on/off) + the current board custom words as JSON. POST: three
        // sub-actions via the hidden field "action": toggle | add | remove. Invalid input
        // → 422 JSON without a 500 (no exception rethrow).
        // IdeaRepository: pending-count badge of the pre-moderation queue (issue 05).
        $moderationAction = new BoardModerationAction($boardRepo, $modConfigRepo, $moderation, $audit, new IdeaRepository($conn), $anonymousSubmissionGate);

        $app->get($accountPrefix . '/admin/boards/{slug}/moderation', $moderationAction->getModeration(...))
            ->add(AuthZMiddleware::accountAdmin($responseFactory, $accountMemberRepo, $authzDenialLogger));

        $app->post($accountPrefix . '/admin/boards/{slug}/moderation', $moderationAction->postModeration(...))
            ->add(AuthZMiddleware::accountAdmin($responseFactory, $accountMemberRepo, $authzDenialLogger));

        // POST /admin/boards/{slug}/block — block/unblock a user account-wide.
        // AuthZ: accountAdmin; CSRF + BlockCheck global;
        // per-action rate limit user:block. `{slug}` serves only the account/
        // AuthZ resolution (findBySlugForAccount, foreign board → 404) — the
        // block itself is account-wide (board_id NULL), not board-scoped.
        $blockRepo      = new BlockRepository($conn);
        $blockRateLimit = $config->rateLimit('user:block');

        $app->post($accountPrefix . '/admin/boards/{slug}/block', new UserBlockAction($boardRepo, $blockRepo, $userRepo, $audit))
            ->add(AuthZMiddleware::accountAdmin($responseFactory, $accountMemberRepo, $authzDenialLogger))
            ->add(RateLimitMiddleware::perAction(
                new RateLimiter($conn),
                $responseFactory,
                'user:block',
                $blockRateLimit['limit'],
                $blockRateLimit['window'],
                static function (ServerRequestInterface $r): ?string {
                    $user = $r->getAttribute(AuthNMiddleware::ATTR_USER);
                    return is_array($user) ? (string) ($user['id'] ?? '') : null;
                },
            ));
    }
}
