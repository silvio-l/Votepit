<?php

declare(strict_types=1);

namespace Votepit\Tests\Http;

use PHPUnit\Framework\TestCase;
use Slim\CallableResolver;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpInternalServerErrorException;
use Slim\Exception\HttpNotFoundException;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Votepit\Http\ReportingErrorHandler;
use Votepit\Monitoring\ErrorReporter;

/** Ops: uncaught exceptions get forwarded to the configured ErrorReporter. */
final class ReportingErrorHandlerTest extends TestCase
{
    public function test_reports_the_exception_in_addition_to_logging(): void
    {
        $reporter = new SpyErrorReporter();
        $handler  = new ReportingErrorHandler(
            new CallableResolver(),
            new ResponseFactory(),
            $reporter,
        );

        $exception = new \RuntimeException('boom');
        $request   = (new ServerRequestFactory())->createServerRequest('GET', '/');

        $handler($request, $exception, displayErrorDetails: false, logErrors: true, logErrorDetails: false);

        self::assertSame($exception, $reporter->reported);
    }

    public function test_does_not_report_when_log_errors_is_disabled(): void
    {
        $reporter = new SpyErrorReporter();
        $handler  = new ReportingErrorHandler(
            new CallableResolver(),
            new ResponseFactory(),
            $reporter,
        );

        $request = (new ServerRequestFactory())->createServerRequest('GET', '/');
        $handler($request, new \RuntimeException('boom'), displayErrorDetails: false, logErrors: false, logErrorDetails: false);

        self::assertNull($reporter->reported);
    }

    public function test_does_not_report_a_routine_404(): void
    {
        $reporter = new SpyErrorReporter();
        $handler  = new ReportingErrorHandler(
            new CallableResolver(),
            new ResponseFactory(),
            $reporter,
        );

        $request   = (new ServerRequestFactory())->createServerRequest('GET', '/nope');
        $exception = new HttpNotFoundException($request);

        $handler($request, $exception, displayErrorDetails: false, logErrors: true, logErrorDetails: false);

        self::assertNull($reporter->reported);
    }

    public function test_does_not_report_a_routine_403(): void
    {
        $reporter = new SpyErrorReporter();
        $handler  = new ReportingErrorHandler(
            new CallableResolver(),
            new ResponseFactory(),
            $reporter,
        );

        $request   = (new ServerRequestFactory())->createServerRequest('GET', '/');
        $exception = new HttpForbiddenException($request);

        $handler($request, $exception, displayErrorDetails: false, logErrors: true, logErrorDetails: false);

        self::assertNull($reporter->reported);
    }

    public function test_still_reports_a_real_500(): void
    {
        $reporter = new SpyErrorReporter();
        $handler  = new ReportingErrorHandler(
            new CallableResolver(),
            new ResponseFactory(),
            $reporter,
        );

        $request   = (new ServerRequestFactory())->createServerRequest('GET', '/');
        $exception = new HttpInternalServerErrorException($request);

        $handler($request, $exception, displayErrorDetails: false, logErrors: true, logErrorDetails: false);

        self::assertSame($exception, $reporter->reported);
    }
}

final class SpyErrorReporter implements ErrorReporter
{
    public ?\Throwable $reported = null;

    public function report(\Throwable $exception): void
    {
        $this->reported = $exception;
    }
}
