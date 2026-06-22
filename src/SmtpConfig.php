<?php

declare(strict_types=1);

namespace Votepit;

final class SmtpConfig
{
    private function __construct(
        public readonly string $host,
        public readonly int $port,
        public readonly string $user,
        public readonly string $pass,
        public readonly string $encryption,
        public readonly string $fromEmail,
        public readonly string $fromName,
    ) {}

    public static function fromArray(array $a): self
    {
        $fromEmail = trim((string) ($a['from_email'] ?? ''));
        if ($fromEmail === '' || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            throw new ConfigException('config.smtp: "from_email" fehlt oder ungültig');
        }
        return new self(
            host: (string) ($a['host'] ?? ''),
            port: (int) ($a['port'] ?? 587),
            user: (string) ($a['user'] ?? ''),
            pass: (string) ($a['pass'] ?? ''),
            encryption: in_array(($a['encryption'] ?? 'tls'), ['tls', 'ssl', ''], true) ? (string) ($a['encryption'] ?? 'tls') : 'tls',
            fromEmail: $fromEmail,
            fromName: (string) ($a['from_name'] ?? 'Votepit'),
        );
    }
}
