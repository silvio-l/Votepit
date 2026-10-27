<?php

declare(strict_types=1);

namespace Votepit\Tests\Support;

use Slim\Interfaces\RouteInterface;

/**
 * Write-route policy for frozen boards (P-21).
 *
 * Every registered POST/PUT/PATCH/DELETE route of the app — self-host and
 * cloud-mode core alike — must appear here with exactly one class:
 *
 *   GUARDED  board-scoped write that runs FrozenBoardGuard::isFrozen() before
 *            its first mutation and answers 423 board_frozen. The second
 *            column names the guarding action (or the action it delegates to).
 *   EXEMPT   board-scoped write that is deliberately still open on a frozen
 *            board. The second column is the justification (owner rule,
 *            W9 decision, open owner question).
 *   ACCOUNT  not board-scoped (account, user, operator, system or
 *            notice-channel writes). It must not touch board data.
 *
 * Adding a write route therefore forces a conscious decision: the policy test
 * fails until the route is classified. Keys are `METHOD /pattern` with the
 * `/{account}` cloud prefix removed, so one entry covers both routing modes.
 *
 * Owner rule (Q1=b, 2026-10-22): a frozen board (frozen_at or
 * compliance_frozen_at) is read-only for everything except making it private
 * and deleting it. Exemptions below are the explicit, documented holes.
 */
final class BoardWriteRoutePolicy
{
    public const GUARDED = 'guarded';
    public const EXEMPT  = 'exempt';
    public const ACCOUNT = 'account';

    /**
     * Board-scoped by path: the class must be GUARDED or EXEMPT, never ACCOUNT.
     * `/admin/worklist/ideas/{id}` is account-scoped in its path but resolves its
     * board from the idea (owner rule 11b), so it counts as board-scoped.
     */
    private const BOARD_SCOPE = '#\{board\}|\{slug\}|/boards\b|/api/v1/(ideas|mcp)|/api/issue-tracker-webhooks|/admin/worklist/ideas/#';

    /** Method + pattern routes carry in the self-host/cloud route tables (cloud prefix stripped). */
    public static function keyOf(RouteInterface $route): string
    {
        $pattern = $route->getPattern();
        if (str_starts_with($pattern, '/{account}/')) {
            $pattern = substr($pattern, strlen('/{account}'));
        }

        return implode('|', $route->getMethods()) . ' ' . $pattern;
    }

    /** @return list<RouteInterface> */
    public static function writeRoutes(RouteInterface ...$routes): array
    {
        return array_values(array_filter(
            $routes,
            static fn (RouteInterface $r): bool => array_intersect($r->getMethods(), ['POST', 'PUT', 'PATCH', 'DELETE']) !== [],
        ));
    }

    public static function isBoardScoped(string $key): bool
    {
        return preg_match(self::BOARD_SCOPE, $key) === 1;
    }

    /**
     * Core policy: every write route of the self-host and cloud-mode core
     * route tables. Cloud-only routes (billing, promotions, plan grants,
     * referrals, billing) are classified in the cloud package's own test.
     *
     * @return array<string, array{0: string, 1: string}> key => [class, detail]
     */
    public static function entries(): array
    {
        $g = self::GUARDED;
        $e = self::EXEMPT;
        $a = self::ACCOUNT;

        return [
            // -- Participant writes on a board (guards in the action) --------
            'POST /{board}/ideas'                                         => [$g, 'IdeaCreateAction::__invoke (also widget and guest path via AnonymousIdeaSubmission)'],
            'POST /{board}/ideas/{id:[0-9]+}'                             => [$g, 'IdeaEditAction::postEdit'],
            'POST /{board}/ideas/{id:[0-9]+}/withdraw'                    => [$g, 'IdeaWithdrawAction::__invoke'],
            'POST /{board}/ideas/{id:[0-9]+}/guest-withdraw'              => [$g, 'GuestIdeaAction::withdraw'],
            'POST /{board}/ideas/{id:[0-9]+}/vote'                        => [$g, 'VoteAction::__invoke'],
            'POST /{board}/ideas/{id:[0-9]+}/vote/reason'                 => [$g, 'VoteReasonAction::__invoke (FrozenBoardGuard::reject, P-03)'],
            'PATCH /{board}/ideas/{id:[0-9]+}/vote/notify-email'          => [$g, 'VoteNotifyEmailAction::__invoke'],
            'POST /{board}/ideas/{id:[0-9]+}/follow'                      => [$g, 'IdeaFollowAction::follow (FrozenBoardGuard::reject, participant mails)'],
            'DELETE /{board}/ideas/{id:[0-9]+}/follow'                    => [$g, 'IdeaFollowAction::unfollow (FrozenBoardGuard::reject, participant mails)'],
            'POST /{board}/ideas/{id:[0-9]+}/comments'                    => [$g, 'CommentCreateAction::__invoke'],
            'POST /{board}/ideas/{id:[0-9]+}/comments/{commentId:[0-9]+}/edit'  => [$g, 'CommentUpdateAction::__invoke'],
            'POST /{board}/ideas/{id:[0-9]+}/comments/{commentId:[0-9]+}/react' => [$g, 'CommentReactionAction::__invoke'],
            'POST /{board}/widget/ideas'                                  => [$g, 'IdeaCreateAction::__invoke with WidgetTokenAuthMiddleware'],
            'POST /{board}/widget/ideas/{id:[0-9]+}/vote'                 => [$g, 'VoteAction::__invoke with WidgetTokenAuthMiddleware'],
            'POST /{board}/widget/ideas/{id:[0-9]+}/comments'             => [$g, 'CommentCreateAction::__invoke with WidgetTokenAuthMiddleware'],

            // -- Admin/moderator writes on ideas, tags, merges (guards in the action)
            'POST /{board}/ideas/{id:[0-9]+}/status'                      => [$g, 'IdeaStatusAction::__invoke'],
            'POST /{board}/ideas/{id:[0-9]+}/pin'                         => [$g, 'IdeaPinAction::__invoke'],
            'POST /{board}/ideas/{id:[0-9]+}/merge'                       => [$g, 'IdeaMergeAction::merge'],
            'POST /{board}/ideas/{id:[0-9]+}/unmerge'                     => [$g, 'IdeaMergeAction::unmerge'],
            'POST /{board}/tags'                                          => [$g, 'TagCreateAction::__invoke'],
            'POST /{board}/tags/{id:[0-9]+}'                              => [$g, 'TagUpdateAction::__invoke'],
            'POST /{board}/tags/{id:[0-9]+}/delete'                       => [$g, 'TagDeleteAction::__invoke'],
            'POST /{board}/ideas/{id:[0-9]+}/tags'                        => [$g, 'IdeaTagAssignAction::__invoke'],
            'POST /{board}/ideas/{id:[0-9]+}/tags/{tagId:[0-9]+}/remove'  => [$g, 'IdeaTagRemoveAction::__invoke'],
            'POST /admin/boards/{slug}/queue/approve'                     => [$g, 'IdeaModerationQueueAction::approve'],
            'POST /admin/boards/{slug}/queue/approve-and-trust'           => [$g, 'IdeaModerationQueueAction::approveAndTrust'],
            'POST /admin/worklist/ideas/{id:[0-9]+}/dismiss'                          => [$g, 'WorklistAction::dismiss (FrozenBoardGuard; owner rule 11b)'],
            'POST /admin/worklist/ideas/{id:[0-9]+}/undismiss'                        => [$g, 'WorklistAction::undismiss (FrozenBoardGuard; owner rule 11b)'],
            'POST /admin/boards/{slug}/moderation/guest-reject-all'       => [$g, 'IdeaModerationQueueAction::rejectAllGuest'],

            // -- Board settings (admin) ---------------------------------------
            'POST /admin/boards/{slug}/branding'                          => [$g, 'BoardBrandingAction::postBranding (frozen: visibility=private only)'],
            'POST /admin/boards/{slug}/branding/pro'                      => [$g, 'BoardProBrandingAction::postProBranding'],
            'POST /admin/boards/{slug}/assets/{kind:cover|favicon|og|logo|wordmark_light|wordmark}'        => [$g, 'BoardAssetAction::upload'],
            'POST /admin/boards/{slug}/assets/{kind:cover|favicon|og|logo|wordmark_light|wordmark}/delete' => [$g, 'BoardAssetAction::delete'],
            'POST /admin/boards/{slug}/assets/terms/accept'               => [$g, 'UploadTermsAction::accept'],
            'PUT /admin/boards/{slug}'                                    => [$g, 'BoardRenameAction::rename'],
            'POST /admin/boards/{slug}/statuses'                          => [$g, 'BoardStatusesAdminAction::create'],
            'PUT /admin/boards/{slug}/statuses/{key:[a-z0-9_]{1,16}}'     => [$g, 'BoardStatusesAdminAction::update'],
            'POST /admin/boards/{slug}/statuses/{key:[a-z0-9_]{1,16}}/delete' => [$g, 'BoardStatusesAdminAction::delete'],
            'POST /admin/boards/{slug}/phases'                            => [$g, 'BoardPhasesAdminAction::post'],
            'POST /admin/boards/{slug}/moderation'                        => [$g, 'BoardModerationAction::postModeration'],
            'PUT /admin/boards/{slug}/link-footer'                        => [$g, 'BoardLinkFooterAction::save'],
            'PUT /admin/boards/{slug}/smtp'                               => [$g, 'BoardSmtpAction::putSmtp'],
            'PUT /admin/boards/{slug}/webhook'                            => [$g, 'BoardWebhookAction::put'],
            'PATCH /admin/boards/{slug}/webhook'                          => [$g, 'BoardWebhookAction::patch'],
            'DELETE /admin/boards/{slug}/webhook'                         => [$g, 'BoardWebhookAction::delete'],
            'PUT /admin/boards/{slug}/chat-notifications/{provider}'      => [$g, 'BoardChatNotificationAction::put'],
            'DELETE /admin/boards/{slug}/chat-notifications/{provider}'   => [$g, 'BoardChatNotificationAction::delete'],
            'PUT /admin/boards/{slug}/widget'                             => [$g, 'BoardWidgetAction::put'],
            'POST /admin/boards/{slug}/widget/secret'                     => [$g, 'BoardWidgetAction::rotateSecret'],
            'POST /admin/boards/{slug}/ideas/{id:[0-9]+}/issue-links/{provider}'   => [$g, 'IdeaIssueLinkAction::post'],
            'DELETE /admin/boards/{slug}/ideas/{id:[0-9]+}/issue-links/{provider}' => [$g, 'IdeaIssueLinkAction::delete'],
            'POST /admin/boards/{slug}/import'                            => [$g, 'BoardImportAction::__invoke'],
            'POST /api/issue-tracker-webhooks/github/{hook:[0-9a-f]{32}}' => [$g, 'IssueTrackerWebhookAction -> IssueStatusSync::markCompleted'],
            'POST /api/issue-tracker-webhooks/linear/{hook:[0-9a-f]{32}}' => [$g, 'IssueTrackerWebhookAction -> IssueStatusSync::markCompleted'],

            // -- API and MCP writes (token-authenticated; delegate to guarded actions)
            'POST /api/v1/ideas'                                          => [$g, 'ApiIdeaAction::submit'],
            'POST /api/v1/ideas/{id:[0-9]+}/vote'                         => [$g, 'ApiIdeaWriteAction::vote (explicit guard)'],
            'POST /api/v1/ideas/{id:[0-9]+}/comments'                     => [$g, 'ApiIdeaWriteAction::delegate -> CommentCreateAction'],
            'POST /api/v1/ideas/{id:[0-9]+}/status'                       => [$g, 'ApiIdeaWriteAction::delegate -> IdeaStatusAction'],
            'POST /api/v1/ideas/{id:[0-9]+}/tags'                         => [$g, 'ApiIdeaWriteAction::delegate -> IdeaTagAssignAction'],
            'DELETE /api/v1/ideas/{id:[0-9]+}/tags/{tagId:[0-9]+}'        => [$g, 'ApiIdeaWriteAction::delegate -> IdeaTagRemoveAction'],
            'POST /api/v1/ideas/{id:[0-9]+}/merge'                        => [$g, 'ApiIdeaWriteAction::delegate -> IdeaMergeAction::merge'],
            'POST /api/v1/ideas/{id:[0-9]+}/unmerge'                      => [$g, 'ApiIdeaWriteAction::delegate -> IdeaMergeAction::unmerge'],
            'POST /api/v1/mcp'                                            => [$g, 'McpAction -> ApiIdeaAction::submit / ApiIdeaWriteAction (guards inherited)'],

            // -- Deliberately open on a frozen board ---------------------------
            'POST /admin/boards'                                          => [$e, 'Creates a new board (no existing board to freeze); plan limit applies.'],
            'POST /admin/boards/{slug}/delete'                            => [$e, 'Owner rule: deleting a frozen board stays allowed (owner right, data minimisation).'],
            'POST /admin/boards/{slug}/operator-acknowledgment'           => [$e, 'Owner rule: the remedy for compliance_frozen_at; blocking it would make a compliance freeze permanent.'],
            'POST /admin/boards/active-set'                               => [$e, 'Unfreeze path: full re-choice of active boards (W9 decision). Account-level, board-freeze writer.'],
            'POST /admin/boards/{slug}/block'                             => [$e, 'Moderator takedown (user block on board). Owner answer 26a (2026-10-25): stays allowed on frozen boards (DSA Art. 16 notice duties).'],
            'POST /{board}/ideas/{id:[0-9]+}/moderate'                    => [$e, 'Moderator takedown (soft delete of idea). Owner answer 26a (2026-10-25): stays allowed on frozen boards (DSA Art. 16 notice duties).'],
            'POST /admin/boards/{slug}/ideas/{id:[0-9]+}/vote-reasons/note/remove' => [$e, 'Moderator takedown of one free-text reason (owner answer 22a, 26a 2026-10-25); the reason choice stays.'],
            'POST /{board}/ideas/{id:[0-9]+}/comments/{commentId:[0-9]+}/delete' => [$e, 'Moderator takedown (soft delete of comment). Owner answer 26a (2026-10-25): stays allowed on frozen boards (DSA Art. 16 notice duties).'],
            'POST /admin/boards/{slug}/smtp/test'                         => [$e, 'W9 decision: test send stays open; sends one mail, writes no board setting.'],
            'POST /{board}/ideas/{id:[0-9]+}/translate'                   => [$e, 'W9 decision: translation caches stay open; writes only translation cache rows.'],
            'POST /{board}/ideas/{id:[0-9]+}/comments/translate'          => [$e, 'W9 decision: translation caches stay open; writes only translation cache rows.'],
            'POST /{board}/translate-intro'                               => [$e, 'W9 decision: translation caches stay open; writes only translation cache rows.'],
            'POST /{board}/ideas/tag-suggestions'                         => [$e, 'Read-only AI tag suggestion (POST for the AI call); writes no row.'],
            'POST /{board}/widget/identify'                               => [$e, 'Widget identity and token issuance; writes user and token rows, no board data. Subsequent writes are guarded.'],
            'POST /operator/boards/{id:[0-9]+}/lock'                      => [$e, 'Platform operator action (locked_at), not an owner write; not part of the frozen rule.'],
            'POST /operator/boards/{id:[0-9]+}/unlock'                    => [$e, 'Platform operator action (locked_at), not an owner write; not part of the frozen rule.'],
            'POST /operator/boards/{id:[0-9]+}/delete'                    => [$e, 'Platform operator action; deletion stays allowed (owner rule).'],
            'POST /appeals'                                               => [$e, 'Appeals stay allowed on frozen boards (owner answer 5a).'],
            'POST /moderation/appeals/{id:[0-9]+}/resolve'                => [$e, 'Appeal resolution stays allowed on frozen boards (owner answer 5a).'],
            'POST /reports'                                               => [$e, 'Abuse-report notice channel (DSA Art. 16); writes one report row, never board content.'],

            // -- Not board-scoped ----------------------------------------------
            'POST /login'                                                 => [$a, 'Session login'],
            'POST /login/password'                                        => [$a, 'Session login'],
            'POST /login/2fa'                                             => [$a, 'Session login (second factor)'],
            'POST /logout'                                                => [$a, 'Session logout'],
            'POST /password/reset/request'                                => [$a, 'Password reset request'],
            'POST /password/reset/confirm'                                => [$a, 'Password reset confirm'],
            'POST /signup/account'                                        => [$a, 'Account signup'],
            'POST /account/password'                                      => [$a, 'User password change'],
            'POST /account/password-reset'                                => [$a, 'User password reset'],
            'PUT /account/participant-mail-preferences'                   => [$a, 'User participant-mail cadence preferences'],
            'POST /participant-mail/unsubscribe'                          => [$a, 'Anonymous one-click unsubscribe (signed token), not board-scoped'],
            'POST /account/totp/setup'                                    => [$a, 'User 2FA setup'],
            'POST /account/totp/confirm'                                  => [$a, 'User 2FA confirm'],
            'POST /account/totp/disable'                                  => [$a, 'User 2FA disable'],
            'POST /account/totp/backup-codes/regenerate'                  => [$a, 'User 2FA backup codes'],
            'POST /account/avatar'                                        => [$a, 'User avatar upload'],
            'DELETE /account/avatar'                                      => [$a, 'User avatar delete'],
            'PUT /account/social-links'                                   => [$a, 'User profile'],
            'PUT /account/privacy'                                        => [$a, 'User profile visibility'],
            'PUT /account/username'                                       => [$a, 'User profile'],
            'DELETE /account/oauth-identities/{provider}'                 => [$a, 'User login identities'],
            'PUT /account/notification-preferences'                       => [$a, 'User notification preferences'],
            'POST /account/notification-email'                            => [$a, 'User notification email'],
            'DELETE /account/notification-email'                          => [$a, 'User notification email'],
            'PUT /account/marketing-consent'                              => [$a, 'User product-news consent (own row only)'],
            'POST /account/delete'                                        => [$a, 'User self-service deletion'],
            'POST /account/delete/cancel'                                 => [$a, 'User self-service deletion cancel'],
            'POST /digest/unsubscribe'                                    => [$a, 'Digest unsubscribe token'],
            'PUT /account/operator-nudges'                                 => [$a, 'User operator nudge switches'],
            'POST /operator-nudges/unsubscribe'                           => [$a, 'Operator nudge unsubscribe token'],
            'POST /notifications/{id:[0-9]+}/read'                        => [$a, 'User notifications'],
            'DELETE /notifications/{id:[0-9]+}'                          => [$a, 'User notifications'],
            'POST /notifications/translate'                               => [$a, 'User notification translation (own inbox)'],
            'POST /oauth/register'                                        => [$a, 'OAuth client registration'],
            'POST /oauth/token'                                           => [$a, 'OAuth token endpoint'],
            'POST /oauth/revoke'                                          => [$a, 'OAuth revocation'],
            'POST /api/oauth/authorize'                                   => [$a, 'OAuth consent'],
            'POST /admin/onboarding/complete'                             => [$a, 'Account onboarding flag'],
            'POST /admin/telemetry-opt-in'                                => [$a, 'Account telemetry flag'],
            'POST /admin/members/{userId:[0-9]+}/remove'                 => [$a, 'Account membership (not board-scoped)'],
            'POST /admin/members/{userId:[0-9]+}/role'                   => [$a, 'Account membership role (not board-scoped)'],
            'POST /admin/members/password-reset'                          => [$a, 'Account member password reset'],
            'PATCH /admin/members/{userId:[0-9]+}/standing'               => [$a, 'Account member standing (account-wide)'],
            'POST /admin/trusted-authors/{userId:[0-9]+}/revoke'         => [$a, 'Account-wide trusted author list; not board content'],
            'POST /admin/invites'                                         => [$a, 'Account invites'],
            'POST /admin/invites/{id:[0-9]+}/revoke'                     => [$a, 'Account invites'],
            'PUT /admin/account'                                          => [$a, 'Account name'],
            'POST /admin/account/delete'                                  => [$a, 'Account deletion'],
            'POST /admin/account/delete/cancel'                           => [$a, 'Account deletion cancel'],
            'POST /admin/support'                                         => [$a, 'Support request to operator'],
            'POST /admin/support/{id:[0-9]+}/reply'                       => [$a, 'Support thread'],
            'POST /admin/support/{id:[0-9]+}/translate'                   => [$a, 'Support thread translation'],
            'POST /admin/tokens'                                          => [$a, 'API token issuance (scope checked on use)'],
            'POST /admin/tokens/{id:[0-9]+}/revoke'                       => [$a, 'API token revocation'],
            // Owner answer 27a (2026-10-25): the tracker connection is account-level, not a
            // board setting, and stays usable on a frozen board. Board-level effects go
            // through issue-links and IssueStatusSync, both guarded.
            'PUT /admin/issue-trackers/{provider}'                        => [$a, 'Owner answer 27a (2026-10-25): account-level tracker connection, stays usable on frozen boards (test: test_frozen_board_does_not_lock_the_account_tracker_connection).'],
            'DELETE /admin/issue-trackers/{provider}'                    => [$a, 'Owner answer 27a (2026-10-25): account-level tracker connection, stays usable on frozen boards (test: test_frozen_board_does_not_lock_the_account_tracker_connection).'],
            'POST /operator/accounts/{id:[0-9]+}/lock'                    => [$a, 'Operator account actions'],
            'POST /operator/accounts/{id:[0-9]+}/unlock'                  => [$a, 'Operator account actions'],
            'POST /operator/accounts/{id:[0-9]+}/delete'                  => [$a, 'Operator account actions'],
            'POST /operator/users/password-reset'                         => [$a, 'Operator user actions'],
            'POST /operator/users/block'                                  => [$a, 'Operator user actions (platform-wide)'],
            'POST /operator/users/end-sessions'                           => [$a, 'Operator user actions'],
            'POST /operator/announcements'                                => [$a, 'Operator announcements'],
            'DELETE /operator/announcements/{id:[0-9]+}'                 => [$a, 'Operator announcements'],
            'POST /operator/faq'                                          => [$a, 'Operator FAQ'],
            'PUT /operator/faq/{id:[0-9]+}'                               => [$a, 'Operator FAQ'],
            'DELETE /operator/faq/{id:[0-9]+}'                           => [$a, 'Operator FAQ'],
            'POST /operator/support/{id:[0-9]+}/reply'                    => [$a, 'Operator support'],
            'POST /operator/support/{id:[0-9]+}/status'                   => [$a, 'Operator support'],
            'POST /operator/support/{id:[0-9]+}/translate'                => [$a, 'Operator support'],
            'POST /operator/reports/{id:[0-9]+}/review'                   => [$a, 'Operator review of abuse reports; writes no board content'],
        ];
    }
}
