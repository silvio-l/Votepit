<?php

declare(strict_types=1);

namespace Votepit\Domain;

use Doctrine\DBAL\Exception as DbalException;
use Votepit\Extension\DeletionBlocked;
use Votepit\Persistence\AccountMemberRepository;
use Votepit\Persistence\UserRepository;

/**
 * Preconditions for a user deleting their own user account (competitor-
 * parity issue 12). Checked when the deletion is requested AND again right
 * before the purge (the user may have gained a blocking role during the
 * grace period) — fail-secure: anything blocking means "not now".
 *
 *   - account owners must delete their account(s) first (ownership
 *     transfer is not a self-service flow yet, see MemberAction) — an
 *     account must never be left without an owner;
 *   - platform operators must have the operator flag revoked first (it is
 *     only ever set via the CLI, never over HTTP — see UserRepository).
 */
final readonly class UserDeletionGuard
{
    public function __construct(
        private AccountMemberRepository $members,
        private UserRepository $users,
    ) {}

    /** @throws DbalException */
    public function check(int $userId): ?DeletionBlocked
    {
        $user = $this->users->findById($userId);
        if (!is_array($user)) {
            return new DeletionBlocked(404, 'not_found', 'User not found.');
        }

        if (PlatformRoles::isOperator($user)) {
            return new DeletionBlocked(
                409,
                'operator_undeletable',
                'Platform operators cannot delete themselves. Have the operator role revoked first.',
            );
        }

        if ($this->members->hasOwnAccount($userId)) {
            return new DeletionBlocked(
                409,
                'owns_accounts',
                'You still own an account. Delete it in its account settings first (or contact support to transfer ownership).',
            );
        }

        return null;
    }
}
