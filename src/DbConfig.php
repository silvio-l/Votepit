<?php

declare(strict_types=1);

namespace Votepit;

final class DbConfig
{
    private function __construct(
        public readonly string $host,
        public readonly int $port,
        public readonly string $name,
        public readonly string $user,
        public readonly string $pass,
        public readonly string $charset,
    ) {}

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
