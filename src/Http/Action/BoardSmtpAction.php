<?php

declare(strict_types=1);

namespace Votepit\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Votepit\Http\Middleware\AccountContextMiddleware;
use Votepit\Logging\AuditLogger;
use Votepit\Persistence\BoardRepository;
use Votepit\Persistence\BoardSmtpSettingsRepository;
use Votepit\Security\EncryptionService;
use Votepit\Security\SmtpHostPolicy;

/**
 * GET /admin/boards/{slug}/smtp — read board SMTP (AuthZ: admin). Never returns the password.
 * PUT /admin/boards/{slug}/smtp — save board SMTP or reset to default
 * (AuthZ: admin, CSRF enforced).
 */
final readonly class BoardSmtpAction
{
    public function __construct(
        private BoardRepository $boardRepo,
        private BoardSmtpSettingsRepository $boardSmtpRepo,
        private EncryptionService $encryptionSvc,
        private AuditLogger $audit,
        private SmtpHostPolicy $hostPolicy = new SmtpHostPolicy(false),
    ) {}

    // -------------------------------------------------------------------------
    // GET /admin/boards/{slug}/smtp
    // -------------------------------------------------------------------------

    /** @param array<string, mixed> $args */
    public function getSmtp(
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
        $row     = $this->boardSmtpRepo->find($boardId);

        $response->getBody()->write((string) json_encode([
            'board_slug'          => $slug,
            'host'                => $row !== null ? (string) ($row['host'] ?? '') : '',
            'port'                => $row !== null ? (int) ($row['port'] ?? 587) : 587,
            'user'                => $row !== null ? (string) ($row['user'] ?? '') : '',
            'encryption'          => $row !== null ? (string) ($row['encryption'] ?? 'tls') : 'tls',
            'from_email'          => $row !== null ? (string) ($row['from_email'] ?? '') : '',
            'from_name'           => $row !== null ? (string) ($row['from_name'] ?? '') : '',
            'password_set'        => $row !== null && ($row['pass'] ?? '') !== '',
            'uses_global_default' => $row === null,
            'verify_peer'         => $row !== null ? (bool) ($row['verify_peer'] ?? true) : true,
        ]));
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }

    // -------------------------------------------------------------------------
    // PUT /admin/boards/{slug}/smtp
    // -------------------------------------------------------------------------

    /** @param array<string, mixed> $args */
    public function putSmtp(
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

        // Reset to the global default?
        if (isset($fields['reset_to_global']) && $fields['reset_to_global'] !== false) {
            $this->boardSmtpRepo->delete($boardId);
            $this->audit->log('board.smtp_reset_to_global', ['board_id' => $boardId]);
            $response->getBody()->write((string) json_encode(['ok' => true, 'reset' => true]));
            return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
        }

        $host       = trim((string) ($fields['host'] ?? ''));
        $port       = (int) ($fields['port'] ?? 587);
        $user       = trim((string) ($fields['user'] ?? ''));
        $encryption = (string) ($fields['encryption'] ?? 'tls');
        $fromEmail  = trim((string) ($fields['from_email'] ?? ''));
        $fromName   = trim((string) ($fields['from_name'] ?? 'Votepit'));
        $password   = (string) ($fields['password'] ?? '');
        $verifyPeer = isset($fields['verify_peer']) ? (bool) $fields['verify_peer'] : true;

        $errors = [];
        // Cloud mode: tenant-supplied relay must be a public target (SSRF guard).
        $hostError = $this->hostPolicy->rejectionReason($host);
        if ($hostError !== null) {
            $errors['host'] = $hostError;
        }
        if ($port < 1 || $port > 65535) {
            $errors['port'] = 'Port must be between 1 and 65535.';
        }
        if (!in_array($encryption, ['tls', 'ssl', ''], true)) {
            $errors['encryption'] = 'Encryption must be "tls", "ssl" or "".';
        }
        if ($fromEmail === '' || filter_var($fromEmail, FILTER_VALIDATE_EMAIL) === false) {
            $errors['from_email'] = 'Sender email is missing or invalid.';
        }

        if ($errors !== []) {
            $response->getBody()->write((string) json_encode([
                'error' => [
                    'key'     => 'validation_error',
                    'message' => 'Validation failed.',
                    'fields'  => $errors,
                ],
            ]));
            return $response->withStatus(422)->withHeader('Content-Type', 'application/json');
        }

        $encryptedPass = $password !== '' ? $this->encryptionSvc->encrypt($password) : null;

        $this->boardSmtpRepo->save($boardId, $host, $port, $user, $encryption, $fromEmail, $fromName, $encryptedPass, $verifyPeer);
        $this->audit->log('board.smtp_updated', ['board_id' => $boardId]);

        $response->getBody()->write((string) json_encode(['ok' => true]));
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }
}
