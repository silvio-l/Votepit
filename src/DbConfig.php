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
    ) {}

    /** @param array<string, mixed> $a */
    public static function fromArray(array $a): self
    {
        $name = trim((string) ($a['name'] ?? ''));
        if ($name === '') {
            throw new ConfigException('config.db: "name" fehlt');
        }
        return new self(
            host: (string) ($a['host'] ?? 'localhost'),
            port: (int) ($a['port'] ?? 3306),
            name: $name,
            user: (string) ($a['user'] ?? ''),
            pass: (string) ($a['pass'] ?? ''),
            charset: (string) ($a['charset'] ?? 'utf8mb4'),
        );
    }

    /** PDO-DSN für Doctrine DBAL. */
    public function dsn(): string
    {
        return "mysql:host={$this->host};port={$this->port};dbname={$this->name};charset={$this->charset}";
    }
}
