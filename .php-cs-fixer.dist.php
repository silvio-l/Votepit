<?php

declare(strict_types=1);

// PHP-CS-Fixer — style consistency + anti-slop (no_unused_imports, ordered
// imports, enforced strict_types). @PHP82Migration matches the stack target.

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
        // Keep compact empty bodies (`) {}` / `class X {}`) — established
        // style in the repo and PER-CS-2.0-compliant; don't pad them out.
        'single_line_empty_body' => true,
        'ordered_imports'      => ['sort_algorithm' => 'alpha'],
        'global_namespace_import' => ['import_classes' => false, 'import_functions' => false, 'import_constants' => false],
    ])
    ->setFinder($finder);
