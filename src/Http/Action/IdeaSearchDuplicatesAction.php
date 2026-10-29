<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Doctrine\DBAL\Exception as DbalException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Config;
use Votepit\Domain\DuplicateCandidateFinder;
use Votepit\Http\Middleware\AccountContextMiddleware;
use Votepit\Http\Middleware\AuthNMiddleware;
use Votepit\Http\Support\AnonymousSubmissionGate;
use Votepit\Http\Support\JsonError;
use Votepit\Logging\AuthzDenialLogger;
use Votepit\Persistence\BoardRepository;
use Votepit\Security\ClientIp;
use Votepit\Security\IpRateKey;
use Votepit\Security\RateLimiter;

/**
 * GET /{board}/ideas/search-duplicates?title=... — as-you-type duplicate
 * recall for the idea-submit form.
 *
 * AuthZ: user (in AppFactory) — matches the surrounding submit flow's trust
 * level (the form itself already requires auth). RateLimit `dupsearch:user`:
 * per-action rate limit (attached in AppFactory).
 *
 * Board-scoped via BoardRepository::findBySlugForAccount() (unknown slug
 * → 404) and DuplicateCandidateFinder (recall bound to board_id) —
 * a duplicate in a foreign board/account can structurally never appear.
 *
 * Surfacing only — no auto-merge (roadmap's explicit "Not included").
 *
 * `confirm=1` (competitor-parity issue 11) additionally asks the optional
 * DuplicateConfirmer of an extension; each candidate then carries
 * `confirmed` (true/false, null without a verdict). The SPA sets it once per
 * title (when the title field loses focus), never on every keystroke, so a
 * confirmer's latency and cost stay off the as-you-type path.
 *
 * Guests (ADR 0006 §5b): the route's AuthZ is anon. Without a session the
 * request is only served on a public board open to guests
 * (AnonymousSubmissionGate::isAvailable()), under its own
 * `idea:dupes:anon:ip` bucket (IPv6 /64-grouped) and through a finder
 * WITHOUT the extension confirmer — `confirm=1` never reaches the paid AI
 * path for guests. Everything else answers with the unchanged
 * AuthZMiddleware::user() 401.
 */
final readonly class IdeaSearchDuplicatesAction
{
    /** Max candidates returned to the client after reranking. */
    private const RESULT_LIMIT = 5;

    /** Below this trimmed length, skip the search entirely (too little signal for as-you-type). */
    private const MIN_QUERY_LENGTH = 3;

    public function __construct(
        private BoardRepository $boardRepo,
        private DuplicateCandidateFinder $duplicateFinder,
        private ?DuplicateCandidateFinder $guestDuplicateFinder = null,
        private ?AnonymousSubmissionGate $anonymousSubmissionGate = null,
        private ?RateLimiter $limiter = null,
        private ?Config $config = null,
        private ?ResponseFactoryInterface $responseFactory = null,
        private ?AuthzDenialLogger $denialLogger = null,
    ) {}

    /** @param array<string, mixed> $args */
    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $slug      = is_string($args['board'] ?? null) ? $args['board'] : '';
        $accountId = (int) $request->getAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID);

        if (!is_array($request->getAttribute(AuthNMiddleware::ATTR_USER))) {
            return $this->forGuest($request, $response, $slug, $accountId);
        }

        $board     = $this->boardRepo->findBySlugForAccount($slug, $accountId);
        if (!is_array($board)) {
            $response->getBody()->write((string) json_encode([
                'error' => ['key' => 'not_found', 'message' => 'Board not found.'],
            ]));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        $queryParams = $request->getQueryParams();
        $confirm     = ($queryParams['confirm'] ?? null) === '1';

        return $this->respond($response, $this->duplicateFinder, (int) $board['id'], $queryParams, $confirm ? $accountId : null);
    }

    private function forGuest(ServerRequestInterface $request, ResponseInterface $response, string $slug, int $accountId): ResponseInterface
    {
        $gate   = $this->anonymousSubmissionGate;
        $finder = $this->guestDuplicateFinder;
        $board  = $gate instanceof AnonymousSubmissionGate ? $this->boardRepo->findPublicBySlugForAccount($slug, $accountId) : null;

        if (!is_array($board) || !$gate instanceof AnonymousSubmissionGate || !$finder instanceof DuplicateCandidateFinder || !$gate->isAvailable($board, $accountId)) {
            if ($this->responseFactory instanceof \Psr\Http\Message\ResponseFactoryInterface && $this->denialLogger instanceof \Votepit\Logging\AuthzDenialLogger) {
                return AnonymousIdeaSubmission::unauthenticated($request, $this->responseFactory, $this->denialLogger);
            }
            $response->getBody()->write('Access denied.');

            return $response->withStatus(401);
        }

        $ipKey = $this->config instanceof Config ? IpRateKey::of(ClientIp::resolve($request, $this->config->trustCloudflareIp)) : null;
        if ($ipKey === null || !$this->limiter instanceof RateLimiter || !$this->config instanceof Config) {
            return JsonError::respond($response, 422, 'rejected', 'The request was rejected.');
        }

        $limit = $this->config->mandatoryRateLimit('idea:dupes:anon:ip', 30, 3600);
        try {
            $allowed = $this->limiter->hit('idea:dupes:anon:ip:' . $ipKey, $limit['limit'], $limit['window']);
        } catch (DbalException) {
            $allowed = true; // fail-open, repo convention for rate limits
        }
        if (!$allowed) {
            return JsonError::respond($response, 429, 'rate_limited', 'Too many requests. Please try again shortly.')
                ->withHeader('Retry-After', (string) $limit['window']);
        }

        // Published-only recall (DuplicateCandidateFinder → findDuplicateCandidates), never confirmed.
        return $this->respond($response, $finder, (int) $board['id'], $request->getQueryParams(), null);
    }

    /** @param array<string, mixed> $queryParams */
    private function respond(ResponseInterface $response, DuplicateCandidateFinder $finder, int $boardId, array $queryParams, ?int $confirmForAccountId): ResponseInterface
    {
        $title = trim(is_scalar($queryParams['title'] ?? null) ? (string) $queryParams['title'] : '');

        if (mb_strlen($title) < self::MIN_QUERY_LENGTH) {
            $response->getBody()->write((string) json_encode(['candidates' => []]));
            return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
        }

        $ranked = $finder->find($boardId, $title, self::RESULT_LIMIT, confirmForAccountId: $confirmForAccountId);

        $candidates = array_map(static fn (array $row): array => [
            'id'         => (int) $row['id'],
            'title'      => (string) $row['title'],
            'status'     => (string) $row['status'],
            'up_count'   => (int) ($row['up_count'] ?? 0),
            'down_count' => (int) ($row['down_count'] ?? 0),
            'similarity' => (float) $row['similarity'],
            'confirmed'  => is_bool($row['confirmed'] ?? null) ? $row['confirmed'] : null,
        ], $ranked);

        $response->getBody()->write((string) json_encode(['candidates' => $candidates]));
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }
}
