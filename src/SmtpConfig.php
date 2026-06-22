<?php

declare(strict_types=1);

namespace Votepit;

final readonly class SmtpConfig
{
    private function __construct(
        public string $host,
        public int $port,
        public string $user,
        public string $pass,
        public string $encryption,
        public string $fromEmail,
        public string $fromName,
    ) {}

    /** @param array<string, mixed> $a */
    public static function fromArray(array $a): self
    {
        $fromEmail = trim((string) ($a['from_email'] ?? ''));
        if ($fromEmail === '' || filter_var($fromEmail, FILTER_VALIDATE_EMAIL) === false) {
            throw new ConfigException('config.smtp: "from_email" fehlt oder ungültig');
        }
        $encryption = (string) ($a['encryption'] ?? 'tls');
        return new self(
            host: (string) ($a['host'] ?? ''),
            port: (int) ($a['port'] ?? 587),
            user: (string) ($a['user'] ?? ''),
            pass: (string) ($a['pass'] ?? ''),
            encryption: in_array($encryption, ['tls', 'ssl', ''], true) ? $encryption : 'tls',
            fromEmail: $fromEmail,
            fromName: (string) ($a['from_name'] ?? 'Votepit'),
        );
    }
}
