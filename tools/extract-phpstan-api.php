<?php

declare(strict_types=1);

/*
 * Extracts the PHPStan API sources from phpstan.phar into
 * .phpstan-api-stubs/. Editors use this copy to index the symbols that
 * src/PhpStan/ implements. PHPStan loads these classes from the PHAR during
 * analysis. The extracted copy supports IDE completion and does not execute.
 */

$root = \dirname(__DIR__);
$pharPath = $root . '/vendor/phpstan/phpstan/phpstan.phar';
$target = $root . '/.phpstan-api-stubs';

if (!\is_file($pharPath)) {
    echo "The tool cannot extract the PHPStan API stubs because phpstan.phar is not installed.\n";
    exit(0);
}

// Remove the stale tree so editors cannot index classes that PHPStan removed.
if (\is_dir($target)) {
    \exec('rm -rf ' . \escapeshellarg($target));
}

$phar = new Phar($pharPath);
$phar->extractTo($target, 'src/', true);

echo \sprintf("Greenlight extracted the PHPStan API sources to %s. Editors can index these sources.\n", $target);
exit(0);
