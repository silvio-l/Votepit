<?php

declare(strict_types=1);

// Rector — Anti-Slop/Modernisierung (dead-code, code-quality, type-declarations,
// privatization). Im CI als reiner Check (`--dry-run`); Autofix nur bewusst lokal.

use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([__DIR__ . '/src', __DIR__ . '/tests'])
    ->withPhpSets(php82: true)
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
        privatization: true,
    );
    // Kein ->withImportNames(): globale Klassen bleiben FQCN (\RuntimeException,
    // \PDO, \JsonException, \Closure) — etablierter Repo-Stil, deckt sich mit der
    // CS-Fixer-Regel global_namespace_import=false (sonst Ping-Pong der Tools).
