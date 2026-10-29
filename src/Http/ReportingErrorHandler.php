<?php

declare(strict_types=1);

namespace Votepit\Http;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpGoneException;
use Slim\Exception\HttpMethodNotAllowedException;
use Slim\Exception\HttpNotFoundException;
use Slim\Exception\HttpTooManyRequestsException;
use Slim\Exception\HttpUnauthorizedException;
use Slim\Handlers\ErrorHandler;
use Slim\Interfaces\CallableResolverInterface;
use Votepit\Monitoring\ErrorReporter;

/**
 * Slim's default ErrorHandler, extended with monitoring reporting (Sentry or
 * similar). Behavior otherwise identical — rendering/logging via error_log
 * stays unchanged, the reporter additionally receives the exception
 * (NullErrorReporter does nothing).
 *
 * Routine client errors (404, 405, 401, 403, 400, 410, 429 — expected
 * behavior, not a bug) are NOT reported to the reporter: otherwise they
 * would burn through the shared org-wide Sentry quota with pure noise
 * (scanner traffic, expired links, mistyped board slugs). error_log still
 * gets them via parent::logError() — that's enough for local diagnosis.
 * Real 5xx errors (HttpInternalServerErrorException, anything not
 * HTTP-typed) continue to be reported without exception.
 */
final class ReportingErrorHandler extends ErrorHandler
{
    private const ROUTINE_EXCEPTIONS = [
        HttpNotFoundException::class,
        HttpMethodNotAllowedException::class,
        HttpUnauthorizedException::class,
        HttpForbiddenException::class,
        HttpBadRequestException::class,
        HttpGoneException::class,
        HttpTooManyRequestsException::class,
    ];

    public function __construct(
        CallableResolverInterface $callableResolver,
        ResponseFactoryInterface $responseFactory,
        private readonly ErrorReporter $reporter,
        ?LoggerInterface $logger = null,
    ) {
        parent::__construct($callableResolver, $responseFactory, $logger);
    }

    protected function logError(string $error): void
    {
        parent::logError($error);

        foreach (self::ROUTINE_EXCEPTIONS as $routineClass) {
            if ($this->exception instanceof $routineClass) {
                return;
            }
        }

        $this->reporter->report($this->exception);
    }
}
