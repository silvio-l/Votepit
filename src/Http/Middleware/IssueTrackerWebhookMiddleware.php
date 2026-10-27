<?php

declare(strict_types=1);

namespace Votepit\Http\Middleware;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Routing\RouteContext;
use Votepit\IssueTracker\IssueTrackerProvider;
use Votepit\IssueTracker\IssueTrackerWebhook;
use Votepit\Persistence\IssueTrackerConnectionRepository;
use Votepit\Security\EncryptionService;

/**
 * Signature-based AuthN for the inbound Linear/GitHub status-sync webhooks
 * (POST /api/issue-tracker-webhooks/{provider}/{hook}) — the complete gate
 * for these session-less, server-to-server routes, same role as the
 * payment-webhook middleware of an extension.
 *
 * Order (fail-secure, no detail in any error response):
 *   1. resolve the connection by its random webhook id — the ONLY DB read
 *      before authentication; unknown id or no stored secret → 404;
 *   2. HMAC-SHA256 over the RAW body (rewound — BodyParsingMiddleware
 *      already consumed the stream) against the decrypted per-connection
 *      secret, constant-time → 401 on mismatch/missing header;
 *   3. Linear only: the signed webhookTimestamp must be within the replay
 *      window → 401.
 * Only then does the action run, with the verified connection attached.
 */
final readonly class IssueTrackerWebhookMiddleware implements MiddlewareInterface
{
    public const ATTR_CONNECTION = 'issue_tracker_connection';
    public const ATTR_PAYLOAD    = 'issue_tracker_payload';

    /** @var \Closure(): int */
    private \Closure $nowMs;

    /** @param null|\Closure(): int $nowMs current time in milliseconds (injectable for tests) */
    public function __construct(
        private IssueTrackerProvider $provider,
        private IssueTrackerConnectionRepository $connections,
        private EncryptionService $secretEnc,
        private ResponseFactoryInterface $responseFactory,
        ?\Closure $nowMs = null,
    ) {
        $this->nowMs = $nowMs ?? static fn (): int => (int) floor(microtime(true) * 1000);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $hook = RouteContext::fromRequest($request)->getRoute()?->getArgument('hook');
        $found = is_string($hook) ? $this->connections->findByWebhookId($this->provider->value, $hook) : null;
        $secretEnc = $found['connection']['webhook_secret_encrypted'] ?? null;
        $secret    = is_string($secretEnc) ? $this->secretEnc->decrypt($secretEnc) : null;
        if ($found === null || $secret === null || $secret === '') {
            return $this->reject(404);
        }

        $body = $request->getBody();
        $body->rewind();
        $rawBody = $body->getContents();

        if (!IssueTrackerWebhook::signatureValid($this->provider, $secret, $rawBody, $request->getHeaderLine($this->provider->signatureHeader()))) {
            return $this->reject(401);
        }

        /** @var mixed $payload */
        $payload = json_decode($rawBody, true);
        if (!is_array($payload) || !IssueTrackerWebhook::withinReplayWindow($this->provider, $payload, ($this->nowMs)())) {
            return $this->reject(401);
        }

        return $handler->handle($request
            ->withAttribute(self::ATTR_CONNECTION, $found['connection'])
            ->withAttribute(self::ATTR_PAYLOAD, $payload));
    }

    private function reject(int $status): ResponseInterface
    {
        $response = $this->responseFactory->createResponse($status);
        $response->getBody()->write((string) json_encode(['error' => ['key' => $status === 404 ? 'not_found' : 'invalid_signature']]));

        return $response->withHeader('Content-Type', 'application/json');
    }
}
