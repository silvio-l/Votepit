<?php

declare(strict_types=1);

// PHP-CS-Fixer — Stil-Konsistenz + Anti-Slop (no_unused_imports, geordnete
// Imports, erzwungene strict_types). @PHP82Migration passt zum Stack-Ziel.

$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__ . '/src', __DIR__ . '/tests'])
    ->name('*.php');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    // Dev-PHP (8.5) ist neuer als das composer-Plattformziel (8.2) — bewusst erlaubt.
    ->setUnsupportedPhpVersionAllowed(true)
    ->setRules([
        '@PSR12'               => true,
        '@PHP82Migration'      => true,
        'declare_strict_types' => true,
        'strict_param'         => true,
        'no_unused_imports'    => true,
        // Kompakte leere Bodies beibehalten (`) {}` / `class X {}`) — etablierter
        // Stil im Repo und PER-CS-2.0-konform; nicht aufblähen.
        'single_line_empty_body' => true,
        'ordered_imports'      => ['sort_algorithm' => 'alpha'],
        'global_namespace_import' => ['import_classes' => false, 'import_functions' => false, 'import_constants' => false],
    ])
    ->setFinder($finder);
