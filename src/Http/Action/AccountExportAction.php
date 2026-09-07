<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Domain\AccountExportService;
use Votepit\Domain\CsvZipExporter;
use Votepit\Http\Middleware\AccountContextMiddleware;
use Votepit\Http\Middleware\AuthNMiddleware;

/**
 * GET /admin/export — account-scoped data export (customer self-
 * export, GDPR Art. 20 data portability). AuthZ: accountOwner (same tier as
 * billing/invite/member mutations — an export is at least as sensitive as
 * those, see BillingAction class doc).
 *
 * `?format=csv` returns a ZIP of one CSV file per table; anything else
 * (including no `format` param) returns the single nested JSON document —
 * JSON is the natural single-file representation of relational account data,
 * so it is the default; CSV is opt-in for spreadsheet tooling.
 *
 * Both responses are served as a download (Content-Disposition: attachment)
 * rather than inline, and the filename embeds the account slug + an ISO
 * timestamp so repeated exports never collide on disk.
 */
final readonly class AccountExportAction
{
    public function __construct(private AccountExportService $export) {}

    public function download(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $accountId = (int) $request->getAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID);

        /** @var array<string, mixed>|null $user */
        $user      = $request->getAttribute(AuthNMiddleware::ATTR_USER);
        $userId    = is_array($user) ? (int) ($user['id'] ?? 0) : 0;

        $data = $this->export->build($accountId, $userId);

        $account = $data['account'] ?? [];
        $slug    = is_array($account) && is_string($account['slug'] ?? null) ? $account['slug'] : (string) $accountId;
        $stamp   = (new \DateTimeImmutable())->format('Ymd-His');

        $format = (string) ($request->getQueryParams()['format'] ?? 'json');

        if ($format === 'csv') {
            $zipBytes = CsvZipExporter::build($data);
            $response->getBody()->write($zipBytes);

            return $response
                ->withStatus(200)
                ->withHeader('Content-Type', 'application/zip')
                ->withHeader('Content-Disposition', sprintf('attachment; filename="votepit-export-%s-%s.zip"', $slug, $stamp));
        }

        $json = (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $response->getBody()->write($json);

        return $response
            ->withStatus(200)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Content-Disposition', sprintf('attachment; filename="votepit-export-%s-%s.json"', $slug, $stamp));
    }
}
