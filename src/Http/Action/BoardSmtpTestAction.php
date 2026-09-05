<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Http\Middleware\AccountContextMiddleware;
use Votepit\Logging\AuditLogger;
use Votepit\Mail\SmtpConfigResolver;
use Votepit\Persistence\BoardRepository;
use Votepit\Persistence\BoardSmtpSettingsRepository;
use Votepit\Security\EncryptionService;
use Votepit\Security\SmtpHostPolicy;

/**
 * POST /admin/boards/{slug}/smtp/test — send a test mail via the resolved
 * board settings (AuthZ: admin, CSRF enforced, rate limit).
 */
final readonly class BoardSmtpTestAction
{
    public function __construct(
        private BoardRepository $boardRepo,
        private SmtpConfigResolver $smtpResolver,
        private BoardSmtpSettingsRepository $boardSmtpRepo,
        private EncryptionService $encryptionSvc,
        private AuditLogger $audit,
        private SmtpHostPolicy $hostPolicy = new SmtpHostPolicy(false),
    ) {}

    /** @param array<string, mixed> $args */
    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $slug      = is_string($args['slug'] ?? null) ? $args['slug'] : '';
        $accountId = (int) $request->getAttribute(AccountContextMiddleware::ATTR_ACCOUNT_ID);
        $board     = $this->boardRepo->findBySlugForAccount($slug, $accountId);
        if (!is_array($board)) {
            $response->getBody()->write((string) json_encode([
                'error' => ['key' => 'not_found', 'message' => 'Board not found.'],
            ]));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        $boardId = (int) $board['id'];

        $rawBody = $request->getParsedBody();
        $fields  = is_array($rawBody) ? $rawBody : [];

        // Target address must be sent explicitly (users.email no longer
        // exists, ADR 0002 — the session only knows email_hmac, no plaintext email).
        $toEmail = trim((string) ($fields['to'] ?? ''));

        if ($toEmail === '' || filter_var($toEmail, FILTER_VALIDATE_EMAIL) === false) {
            $response->getBody()->write((string) json_encode([
                'error' => ['key' => 'invalid_recipient', 'message' => 'A valid recipient email address is required.'],
            ]));
            return $response->withStatus(422)->withHeader('Content-Type', 'application/json');
        }

        $host      = trim((string) ($fields['host'] ?? ''));
        $useInline = $host !== '';

        if ($useInline) {
            // Cloud mode: an inline test host is an arbitrary, tenant-chosen
            // TCP target — apply the same public-target policy as on save.
            $hostError = $this->hostPolicy->rejectionReason($host);
            if ($hostError !== null) {
                $response->getBody()->write((string) json_encode([
                    'error' => [
                        'key'     => 'validation_error',
                        'message' => 'Validation failed.',
                        'fields'  => ['host' => $hostError],
                    ],
                ]));
                return $response->withStatus(422)->withHeader('Content-Type', 'application/json');
            }

            $port       = (int) ($fields['port'] ?? 587);
            $user2      = trim((string) ($fields['user'] ?? ''));
            $encryption = (string) ($fields['encryption'] ?? 'tls');
            $fromEmail  = trim((string) ($fields['from_email'] ?? ''));
            $fromName   = trim((string) ($fields['from_name'] ?? 'Votepit'));
            $password   = (string) ($fields['password'] ?? '');

            // If password is empty → load it from board settings or global.
            if ($password === '') {
                $boardRow = $this->boardSmtpRepo->find($boardId);
                $encPw    = is_array($boardRow) ? (string) ($boardRow['pass'] ?? '') : '';
                $password = ($encPw !== '') ? ($this->encryptionSvc->decrypt($encPw) ?? '') : '';
            }

            try {
                $smtpConfig = \Votepit\SmtpConfig::fromArray([
                    'host'       => $host,
                    'port'       => $port,
                    'user'       => $user2,
                    'pass'       => $password,
                    'encryption' => $encryption,
                    'from_email' => $fromEmail !== '' ? $fromEmail : 'noreply@example.com',
                    'from_name'  => $fromName,
                ]);
            } catch (\Votepit\ConfigException $e) {
                $response->getBody()->write((string) json_encode([
                    'error' => ['key' => 'config_error', 'message' => $e->getMessage()],
                ]));
                return $response->withStatus(422)->withHeader('Content-Type', 'application/json');
            }
        }

        try {
            if (!$useInline) {
                // Resolved board settings (board → global → config.php).
                // SmtpConfigResolver re-checks against the host policy here
                // (DNS rebinding since save) — a failure lands in the same
                // catch as a real send failure.
                $smtpConfig = $this->smtpResolver->resolve($boardId);
            }
            $testMail = \Votepit\Mail\MailTemplate::render(
                'SMTP test successful',
                [
                    "This is a Votepit test email for board \"{$slug}\".",
                    'If you can see this message, the SMTP configuration works.',
                ],
            );
            $testMailer = new \Votepit\Mail\SymfonyMailerAdapter($smtpConfig);
            $testMailer->send(
                $toEmail,
                'Votepit SMTP test (board: ' . $slug . ')',
                $testMail['text'],
                $testMail['html'],
                $testMail['image'],
            );
        } catch (\Throwable $e) {
            // Raw exception messages from SMTP clients can leak internal
            // network/DNS/auth details (a port-scanning primitive for an
            // authenticated tenant admin against hosts they otherwise
            // couldn't reach) — log server-side, the client only gets a
            // generic message.
            $this->audit->log('smtp_test.failed', [
                'board_id' => $boardId,
                'account_id' => $accountId,
                'error' => $e->getMessage(),
            ]);
            $response->getBody()->write((string) json_encode([
                'error' => ['key' => 'send_failed', 'message' => 'SMTP test failed. See the server log for details.'],
            ]));
            return $response->withStatus(502)->withHeader('Content-Type', 'application/json');
        }

        $response->getBody()->write((string) json_encode(['ok' => true, 'recipient' => $toEmail]));
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }
}
