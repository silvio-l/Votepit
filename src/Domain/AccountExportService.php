<?php

declare(strict_types=1);

namespace Votepit\Domain;

use Doctrine\DBAL\Exception as DbalException;
use Votepit\Persistence\AccountMemberRepository;
use Votepit\Persistence\AccountRepository;
use Votepit\Persistence\ApiTokenRepository;
use Votepit\Persistence\BlockRepository;
use Votepit\Persistence\BoardRepository;
use Votepit\Persistence\BoardSmtpSettingsRepository;
use Votepit\Persistence\CommentRepository;
use Votepit\Persistence\IdeaRepository;
use Votepit\Persistence\InviteRepository;
use Votepit\Persistence\ModerationConfigRepository;
use Votepit\Persistence\UserRepository;
use Votepit\Persistence\VoteRepository;

/**
 * Account-scoped data export (customer self-export, GDPR Art. 20 —
 * data portability). Builds ONE nested document containing every row this
 * account owns — the read-side mirror of bin/cleanup-expired-accounts.php's
 * cascading DELETE (that script enumerates every account-scoped table for
 * deletion; this service enumerates the exact same set for export).
 *
 * Cross-tenant-leak discipline (same standard as every other account-scoped
 * chokepoint, e.g. BoardRepository/IdeaRepository): every single query here
 * is either a direct `WHERE account_id = :account_id` or a JOIN chain back to
 * `boards.account_id` for tables that only carry board_id/idea_id (ideas,
 * votes, comments, board_blocklist, board_smtp_settings). An export endpoint
 * is exactly as dangerous a leak vector as a read endpoint (roadmap risk
 * note) — this class reuses the SAME repository methods (or tightly-scoped
 * siblings added alongside them) that the rest of the app already trusts,
 * rather than inventing a second, parallel query surface.
 *
 * PII discipline (ADR 0002 — email pseudonymization): NOTHING here ever
 * derives or exposes a plaintext email. Member/voter/commenter identity is
 * always the internal numeric user_id already stored on the row — the same
 * identifier account_members/votes/comments/ideas use everywhere else. This
 * class never touches IdentityHasher and never reads users.email_hmac either
 * (an account owner's own view of "who is member #42" is already all the
 * identity this export needs; the HMAC itself is not portability-relevant
 * data belonging to the exporting account).
 *
 * Secrets discipline: api_tokens/board_smtp_settings rows are exported as
 * METADATA ONLY (see ApiTokenRepository::listForAccount() /
 * BoardSmtpSettingsRepository::listMetadataForAccount()) — never a token
 * hash, never an SMTP password (encrypted or decrypted).
 *
 * `owner_notification_preferences` (notification-preferences feature) is the
 * ONE deliberate exception to "account-scoped only": `notification_email`
 * and the four preference flags live on `users`, not on any account-scoped
 * table, so they are personal data of whichever individual triggered this
 * export — never of the `members` list as a whole. Exporting every member's
 * notification_email here would leak one member's plaintext PII to another
 * (the account owner), the exact leak this class's `members` projection
 * (user_id/role only, see class doc above) already guards against. Scoping
 * this to $requestingUserId (always the account owner — AuthZ: accountOwner
 * on the one caller, AccountExportAction) keeps it to "your own data only".
 */
final readonly class AccountExportService
{
    public function __construct(
        private AccountRepository $accounts,
        private AccountMemberRepository $members,
        private BoardRepository $boards,
        private IdeaRepository $ideas,
        private VoteRepository $votes,
        private CommentRepository $comments,
        private InviteRepository $invites,
        private ApiTokenRepository $apiTokens,
        private BoardSmtpSettingsRepository $boardSmtp,
        private ModerationConfigRepository $moderation,
        private BlockRepository $blocks,
        private UserRepository $users,
    ) {}

    /**
     * Assembles the full export document for one account. Every top-level
     * key except `exported_at` and `account` is a `list<array<string, mixed>>`
     * — deliberately uniform shape so both the JSON writer and the
     * CSV-per-table writer (CsvZipExporter) can iterate it generically.
     *
     * $requestingUserId is the account owner performing the export (never
     * trust the client for this — the caller passes the AuthN-verified
     * user id) — see class doc on `owner_notification_preferences`.
     *
     * @return array<string, mixed>
     * @throws DbalException
     */
    public function build(int $accountId, int $requestingUserId): array
    {
        $account      = $this->accounts->findById($accountId);
        $ownerPrefs   = $this->users->findNotificationSettings($requestingUserId);

        return [
            'exported_at'                    => (new \DateTimeImmutable())->format(DATE_ATOM),
            'account'                        => $account ?? [],
            'boards'                         => $this->boards->listFullForAccount($accountId),
            'ideas'                          => $this->ideas->listAllForAccount($accountId),
            'votes'                          => $this->votes->listForAccount($accountId),
            'comments'                       => $this->comments->listForAccount($accountId),
            'members'                        => $this->members->listForAccount($accountId),
            'invites'                        => $this->invites->listAllForAccount($accountId),
            'api_tokens'                     => $this->apiTokens->listForAccount($accountId),
            'board_smtp_settings'            => $this->boardSmtp->listMetadataForAccount($accountId),
            'moderation_blocklist_words'     => $this->moderation->listWordsForAccount($accountId),
            'blocked_users'                  => $this->blocks->listForAccount($accountId),
            'owner_notification_preferences' => $ownerPrefs !== null ? [$ownerPrefs] : [],
        ];
    }
}
