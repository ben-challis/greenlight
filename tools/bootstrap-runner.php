<?php

declare(strict_types=1);

/*
 * Temporary bootstrap test runner, deleted once Greenlight runs its own suite.
 *
 * Deliberately dumb: it discovers *Test.php files under the given directories,
 * maps them to classes by PSR-4 (tests/ => Greenlight\Tests\), instantiates each
 * class with a no-argument constructor, and runs public methods marked #[Test],
 * with #[Before] and #[After] hooks. Attributes are matched by name so this file
 * has no dependency on framework code. No data sets, no injection, no parallelism.
 * Nothing may depend on this runner's behaviour.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

$rawArgs = $_SERVER['argv'] ?? null;
$paths = [];

if (is_array($rawArgs)) {
    foreach (array_slice($rawArgs, 1) as $arg) {
        if (is_string($arg)) {
            $paths[] = $arg;
        }
    }
}

exit(greenlight_bootstrap_main($paths));

/**
 * @param list<string> $paths
 */
function greenlight_bootstrap_main(array $paths): int
{
    if ($paths === []) {
        fwrite(STDERR, "Usage: php tools/bootstrap-runner.php <test-dir> [<test-dir>...]\n");

        return 2;
    }

    $projectRoot = dirname(__DIR__);
    $files = [];

    foreach ($paths as $path) {
        $absolute = str_starts_with($path, '/') ? $path : $projectRoot . '/' . $path;

        if (!is_dir($absolute)) {
            fwrite(STDERR, sprintf("Not a directory: %s\n", $path));

            return 2;
        }

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($absolute, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && str_ends_with($file->getPathname(), 'Test.php')) {
                $files[] = $file->getPathname();
            }
        }
    }

    sort($files);

    $tests = 0;
    $failures = [];

    foreach ($files as $file) {
        $class = greenlight_bootstrap_class_for_file($projectRoot, $file);

        if ($class === null || !class_exists($class)) {
            $failures[] = [$file, sprintf('Could not resolve a loadable class for %s.', $file)];

            continue;
        }

        $reflection = new ReflectionClass($class);

        if ($reflection->isAbstract()) {
            continue;
        }

        $testMethods = greenlight_bootstrap_methods($reflection, 'Test');

        if ($testMethods === []) {
            continue;
        }

        foreach ($testMethods as $method) {
            ++$tests;
            $error = greenlight_bootstrap_run_test($reflection, $method);

            if ($error !== null) {
                $failures[] = [$class . '::' . $method->getName(), $error];
                fwrite(STDOUT, 'F');
            } else {
                fwrite(STDOUT, '.');
            }
        }
    }

    fwrite(STDOUT, "\n");

    foreach ($failures as [$where, $why]) {
        fwrite(STDOUT, sprintf("\nFAIL %s\n     %s\n", $where, $why));
    }

    if ($tests === 0) {
        fwrite(STDERR, "No tests found. A misconfigured run must not pass.\n");

        return 1;
    }

    fwrite(STDOUT, sprintf("\n%d tests, %d failures (bootstrap runner)\n", $tests, count($failures)));

    return $failures === [] ? 0 : 1;
}

/**
 * @param non-empty-string $file
 */
function greenlight_bootstrap_class_for_file(string $projectRoot, string $file): ?string
{
    $prefix = $projectRoot . '/tests/';

    if (!str_starts_with($file, $prefix)) {
        return null;
    }

    $relative = substr($file, strlen($prefix), -strlen('.php'));

    return 'Greenlight\\Tests\\' . str_replace('/', '\\', $relative);
}

/**
 * @param ReflectionClass<object> $class
 *
 * @return list<ReflectionMethod>
 */
function greenlight_bootstrap_methods(ReflectionClass $class, string $attributeShortName): array
{
    $methods = [];

    foreach ($class->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if ($method->isStatic() || $method->isAbstract()) {
            continue;
        }

        foreach ($method->getAttributes() as $attribute) {
            $name = $attribute->getName();
            $shortName = ($pos = strrpos($name, '\\')) === false ? $name : substr($name, $pos + 1);

            if ($shortName === $attributeShortName) {
                $methods[] = $method;

                break;
            }
        }
    }

    return $methods;
}

/**
 * @param ReflectionClass<object> $class
 */
function greenlight_bootstrap_run_test(ReflectionClass $class, ReflectionMethod $testMethod): ?string
{
    $constructor = $class->getConstructor();

    if ($constructor !== null && $constructor->getNumberOfRequiredParameters() > 0) {
        return 'Bootstrap runner only supports no-argument constructors.';
    }

    try {
        $instance = $class->newInstance();
    } catch (Throwable $e) {
        return greenlight_bootstrap_describe($e);
    }

    $error = null;

    try {
        foreach (greenlight_bootstrap_methods($class, 'Before') as $before) {
            $before->invoke($instance);
        }

        $testMethod->invoke($instance);
    } catch (Throwable $e) {
        $error = greenlight_bootstrap_describe($e);
    }

    try {
        foreach (greenlight_bootstrap_methods($class, 'After') as $after) {
            $after->invoke($instance);
        }
    } catch (Throwable $e) {
        $error ??= greenlight_bootstrap_describe($e);
    }

    return $error;
}

function greenlight_bootstrap_describe(Throwable $e): string
{
    return sprintf('%s: %s (%s:%d)', $e::class, $e->getMessage(), $e->getFile(), $e->getLine());
}
