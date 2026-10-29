<?php

declare(strict_types=1);

namespace Votepit\Domain;

use Doctrine\DBAL\Exception as DbalException;
use Votepit\Persistence\AccountMemberRepository;
use Votepit\Persistence\CommentReactionRepository;
use Votepit\Persistence\CommentRepository;
use Votepit\Persistence\IdeaRepository;
use Votepit\Persistence\MarketingConsentRepository;
use Votepit\Persistence\OAuthIdentityRepository;
use Votepit\Persistence\UserRepository;
use Votepit\Persistence\UserSocialLinkRepository;
use Votepit\Persistence\VoteRepository;

/**
 * Per-user data export (GDPR Art. 15 access / Art. 20 portability for every
 * user, competitor-parity issue 12) — the user-scoped sibling of
 * AccountExportService (which exports a whole account for its owner).
 *
 * Scope: exactly the rows that are the caller's own personal data, across
 * every account they contributed to — keyed by the session user id only,
 * never by client input, so no other person's data can be selected.
 * Contributions are exported with account/board slugs for context, never
 * with other people's content.
 *
 * PII/secrets discipline (ADR 0002): no email_hmac, no password hash, no
 * TOTP secret, no OAuth subject id, no anonymous-vote ciphertext — only
 * whether a password/2FA is set. notification_email IS exported: it is the
 * one plaintext address we hold, and it is the caller's own. The same holds for
 * the marketing delivery address and the marketing consent record (migration 0112).
 */
final readonly class UserDataExportService
{
    public function __construct(
        private UserRepository $users,
        private UserSocialLinkRepository $socialLinks,
        private AccountMemberRepository $members,
        private OAuthIdentityRepository $oauthIdentities,
        private IdeaRepository $ideas,
        private CommentRepository $comments,
        private VoteRepository $votes,
        private CommentReactionRepository $reactions,
        private MarketingConsentRepository $marketing,
    ) {}

    /**
     * @return array<string, mixed>
     * @throws DbalException
     */
    public function build(int $userId): array
    {
        $user        = $this->users->findById($userId) ?? [];
        $credentials = $this->users->findByIdWithCredentials($userId) ?? [];
        $avatar      = is_string($user['avatar_filename'] ?? null) ? $user['avatar_filename'] : null;

        return [
            'exported_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'profile'     => [
                'public_id'             => $user['public_id'] ?? null,
                'username'              => $user['username'] ?? null,
                'profile_visible'       => (bool) ($user['profile_visible'] ?? false),
                'avatar_url'            => $avatar !== null ? '/avatar/' . $avatar : null,
                ...$this->socialLinks->getForUser($userId),
                'has_password'          => is_string($credentials['password_hash'] ?? null),
                'two_factor_enabled'    => ($user['totp_enabled_at'] ?? null) !== null,
                'verified_at'           => $user['verified_at'] ?? null,
                'created_at'            => $user['created_at'] ?? null,
                'deletion_scheduled_at' => $user['deletion_scheduled_at'] ?? null,
            ],
            'notification_preferences' => $this->users->findNotificationSettings($userId) ?? [],
            'marketing_consent'        => $this->marketing->exportForUser($userId),
            'memberships'              => $this->members->membershipsWithSlugFor($userId),
            'linked_identities'        => $this->oauthIdentities->findAllByUserId($userId),
            'ideas'                    => $this->ideas->listByAuthorWithBoard($userId),
            'comments'                 => $this->comments->listByAuthorWithBoard($userId),
            'votes'                    => $this->votes->listByUserWithBoard($userId),
            'anonymous_votes'          => $this->votes->listLinkedAnonymousForUser($userId),
            'reactions'                => $this->reactions->listByUser($userId),
        ];
    }
}
