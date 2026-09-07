<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Persistence\AbuseReportRepository;
use Votepit\Persistence\AccountRepository;
use Votepit\Persistence\BoardRepository;
use Votepit\Persistence\IdeaRepository;
use Votepit\Persistence\SupportRequestRepository;
use Votepit\Persistence\UserRepository;

/**
 * GET /operator/usage — platform-wide usage overview.
 *
 * AuthZ: AuthZMiddleware::operator(). Cheaply queryable COUNT/GROUP BY
 * aggregates only — deliberately no new aggregation infrastructure, per the
 * roadmap scope note.
 */
final readonly class OperatorUsageAction
{
    public function __construct(
        private AccountRepository $accounts,
        private BoardRepository $boards,
        private IdeaRepository $ideas,
        private UserRepository $users,
        private AbuseReportRepository $reports,
        private SupportRequestRepository $supportRequests,
    ) {}

    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $response->getBody()->write((string) json_encode([
            'accounts_total'         => $this->accounts->countAll(),
            'accounts_by_plan'       => $this->accounts->countByPlan(),
            'boards_total'           => $this->boards->countAll(),
            'ideas_total'            => $this->ideas->countAll(),
            'signups_last_7_days'    => $this->users->countCreatedSince(new \DateTimeImmutable('-7 days')),
            'open_reports'           => $this->reports->countOpen(),
            'open_support_requests'  => $this->supportRequests->countOpen(),
        ]));
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }
}
