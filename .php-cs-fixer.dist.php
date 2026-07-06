<?php

declare(strict_types=1);
use PhpCsFixer\Config;
use PhpCsFixer\Finder;

// bin/greenlight is excluded: its shebang line confuses import-adding fixers
// into placing use statements before declare(strict_types=1), which is fatal.
// Fixtures are excluded because they encode deliberate patterns.
$finder = Finder::create()
    ->in([__DIR__ . '/src', __DIR__ . '/tests', __DIR__ . '/tools'])
    ->exclude('Fixture')
    ->append([__FILE__, __DIR__ . '/rector.php']);

return (new Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PER-CS2.0' => true,
        '@PER-CS2.0:risky' => true,
        'declare_strict_types' => true,
        'fully_qualified_strict_types' => ['import_symbols' => true],
        'global_namespace_import' => ['import_classes' => false, 'import_constants' => false, 'import_functions' => false],
        'no_unused_imports' => true,
        'ordered_imports' => ['imports_order' => ['class', 'function', 'const'], 'sort_algorithm' => 'alpha'],
        'strict_comparison' => true,
        'strict_param' => true,
        'trailing_comma_in_multiline' => ['elements' => ['arguments', 'array_destructuring', 'arrays', 'match', 'parameters']],
    ])
    ->setFinder($finder);
