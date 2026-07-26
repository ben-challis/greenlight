<?php

declare(strict_types=1);
use PhpCsFixer\Config;
use PhpCsFixer\Finder;

// Exclude bin/greenlight because its shebang causes import fixers to put use statements
// before declare(strict_types=1). PHP stops with an error if this occurs.
// Exclude fixtures because they contain deliberate patterns.
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
        'native_constant_invocation' => [
            'fix_built_in' => true,
            'include' => ['XDEBUG_CC_DEAD_CODE', 'XDEBUG_CC_UNUSED'],
            'scope' => 'all',
            'strict' => true,
        ],
        'native_function_invocation' => ['include' => ['@all'], 'scope' => 'all', 'strict' => true],
        'no_unused_imports' => true,
        'ordered_imports' => ['imports_order' => ['class', 'function', 'const'], 'sort_algorithm' => 'alpha'],
        'strict_comparison' => true,
        'strict_param' => true,
        'trailing_comma_in_multiline' => ['elements' => ['arguments', 'array_destructuring', 'arrays', 'match', 'parameters']],
    ])
    ->setFinder($finder);
