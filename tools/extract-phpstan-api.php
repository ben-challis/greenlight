<?php

declare(strict_types=1);

/*
 * Extracts the PHPStan API sources from phpstan.phar into .phpstan-api-stubs/
 * so editors can index the symbols that src/PhpStan/ implements. PHPStan
 * itself autoloads these classes from the phar at analysis time; the
 * extracted copy exists only for IDE completion and is never executed.
 */

$root = dirname(__DIR__);
$pharPath = $root . '/vendor/phpstan/phpstan/phpstan.phar';
$target = $root . '/.phpstan-api-stubs';

if (!is_file($pharPath)) {
    echo "phpstan.phar is not installed; skipping the PHPStan API stub extraction.\n";
    exit(0);
}

// A stale tree would keep classes removed upstream indexable, so start fresh.
if (is_dir($target)) {
    exec('rm -rf ' . escapeshellarg($target));
}

$phar = new Phar($pharPath);
$phar->extractTo($target, 'src/', true);

echo sprintf("Extracted the PHPStan API sources to %s for IDE indexing.\n", $target);
exit(0);
