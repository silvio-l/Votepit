<?php

declare(strict_types=1);

namespace Votepit;

final readonly class DbConfig
{
    private function __construct(
        public string $host,
        public int $port,
        public string $name,
        public string $user,
        public string $pass,
        public string $charset,
        public ?string $sslCa,
    ) {}

    /** @param array<string, mixed> $a */
    public static function fromArray(array $a): self
    {
        $name = trim((string) ($a['name'] ?? ''));
        if ($name === '') {
            throw new ConfigException('config.db: "name" is missing');
        }
        $sslCa = trim((string) ($a['ssl_ca'] ?? ''));
        return new self(
            host: (string) ($a['host'] ?? 'localhost'),
            port: (int) ($a['port'] ?? 3306),
            name: $name,
            user: (string) ($a['user'] ?? ''),
            pass: (string) ($a['pass'] ?? ''),
            charset: (string) ($a['charset'] ?? 'utf8mb4'),
            sslCa: $sslCa !== '' ? $sslCa : null,
        );
    }

    /** PDO DSN for Doctrine DBAL. */
    public function dsn(): string
    {
        return "mysql:host={$this->host};port={$this->port};dbname={$this->name};charset={$this->charset}";
    }
}
