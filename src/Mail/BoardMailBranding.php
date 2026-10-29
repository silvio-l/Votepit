<?php

declare(strict_types=1);

namespace Votepit\Mail;

use Doctrine\DBAL\Connection;
use Votepit\Domain\AccountPlanResolver;
use Votepit\Domain\BrandResolver;
use Votepit\Domain\PlanPolicy;
use Votepit\Persistence\AccountMemberRepository;
use Votepit\Persistence\AccountRepository;
use Votepit\Persistence\BoardRepository;

/**
 * Branding for board-bound notification mails (P-32, gate `branded_mails` =
 * Pro). Resolves the board's branding through BrandResolver (the same single
 * place the board page uses), the account plan without a viewer bypass, and
 * the board name as sender display name (MailSenderName).
 *
 * Returns null — and the caller sends the unbranded Votepit mail — when the
 * plan does not include `branded_mails`, the board does not exist, or the
 * lookup fails. Fail-secure: a branding error never blocks a mail and never
 * changes the envelope address.
 *
 * Auth, magic-link, password and invite mails never go through this class.
 */
final readonly class BoardMailBranding
{
    public function __construct(
        private BrandResolver $brands,
        private BoardRepository $boards,
        private PlanPolicy $planPolicy,
    ) {}

    public static function forConnection(Connection $conn, AccountRepository $accounts, PlanPolicy $planPolicy): self
    {
        $plans = new AccountPlanResolver($accounts, new AccountMemberRepository($conn), $planPolicy);

        return new self(new BrandResolver($plans, $planPolicy), new BoardRepository($conn), $planPolicy);
    }

    /**
     * @return array{brand: MailBrand, sender_name: ?string}|null
     */
    public function forBoard(int $boardId): ?array
    {
        $board = $this->boards->findByIdAny($boardId);
        if ($board === null) {
            return null;
        }

        $plan = $this->brands->accountPlanForBoard($board);
        if (!$this->planPolicy->featureAllowed($plan, 'branded_mails')) {
            return null;
        }

        $resolved = $this->brands->forBoard($board, $plan);
        $brand    = MailBrand::fromColors(
            $resolved->primaryColor,
            $resolved->secondaryColor,
            MailTemplate::COLOR_INK,
            MailTemplate::COLOR_CTA,
        );

        $name = is_string($board['name'] ?? null) ? $board['name'] : '';

        return [
            'brand'       => $brand,
            'sender_name' => MailSenderName::forName($name),
        ];
    }
}
