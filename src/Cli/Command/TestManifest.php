<?php

declare(strict_types=1);

namespace Greenlight\Cli\Command;

use Greenlight\Cli\Configuration\ConfigurationLoader;
use Greenlight\Config\SuiteConfiguration;
use Greenlight\Discovery\Plan\ExecutionPlan;
use Greenlight\Discovery\Plan\PlanEntry;

/**
 * Makes the public test discovery manifest without internal wire payloads.
 *
 * @internal
 */
final class TestManifest
{
    private function __construct() {}

    /**
     * @param list<SuiteConfiguration> $suites
     * @param array{int, int}|null $shard
     *
     * @return array<string, mixed>
     */
    public static function document(
        ExecutionPlan $plan,
        array $suites,
        ?array $shard,
        string $workingDirectory,
    ): array {
        $suitePaths = self::suitePaths($suites, $workingDirectory);

        return [
            'version' => 1,
            'order' => [
                'tests' => 'plan',
                'completion' => 'not-applicable',
                'seed' => $plan->seed,
            ],
            'shard' => $shard === null ? null : [
                'index' => $shard[0],
                'count' => $shard[1],
            ],
            'tests' => \array_map(
                static fn(PlanEntry $entry): array => self::test($entry, $suitePaths),
                $plan->entries,
            ),
        ];
    }

    /**
     * @param array<non-empty-string, list<string>> $suitePaths
     * @return array<string, mixed>
     */
    private static function test(PlanEntry $entry, array $suitePaths): array
    {
        $definition = $entry->definition;
        $method = new \ReflectionMethod($definition->class, $definition->method);
        $file = $method->getFileName();
        $line = $method->getStartLine();
        $classFile = \class_exists($definition->class)
            ? new \ReflectionClass($definition->class)->getFileName()
            : false;

        if (!\is_string($file) || $file === '' || !\is_int($line) || $line < 1) {
            throw new \LogicException(\sprintf(
                'Test method %s::%s() does not have a source location.',
                $definition->class,
                $definition->method,
            ));
        }

        $groups = $definition->groups;
        $resources = $definition->scheduling->resources;
        \sort($groups, \SORT_STRING);
        \sort($resources, \SORT_STRING);

        return [
            'id' => (string) $entry->id,
            'class' => $definition->class,
            'method' => $definition->method,
            'dataSetKey' => $entry->dataSetKey,
            'source' => [
                'file' => $file,
                'line' => $line,
            ],
            'groups' => $groups,
            'suites' => \is_string($classFile) ? self::memberships($classFile, $suitePaths) : [],
            'skip' => [
                'present' => $definition->skip->reason !== null || $definition->skip->condition !== null,
                'condition' => $definition->skip->condition,
            ],
            'retry' => [
                'additionalAttempts' => $definition->retry->times ?? 0,
                'onlyOn' => $definition->retry->onlyOn,
            ],
            'timeoutSeconds' => $definition->execution->timeoutSeconds,
            'captureOutput' => $definition->execution->capture,
            'noExpectations' => $definition->execution->noExpectations,
            'resources' => $resources,
            'isolated' => $definition->scheduling->isolated,
            'allowParallel' => $definition->scheduling->allowParallel,
        ];
    }

    /**
     * @param list<SuiteConfiguration> $suites
     * @return array<non-empty-string, list<string>>
     */
    private static function suitePaths(array $suites, string $workingDirectory): array
    {
        $resolved = [];

        foreach ($suites as $suite) {
            foreach ($suite->paths as $path) {
                $absolute = ConfigurationLoader::absolutePath($path, $workingDirectory);
                $real = \realpath($absolute);
                $resolved[$suite->name][] = $real === false ? $absolute : $real;
            }
        }

        return $resolved;
    }

    /**
     * @param array<non-empty-string, list<string>> $suitePaths
     * @return list<non-empty-string>
     */
    private static function memberships(string $file, array $suitePaths): array
    {
        $memberships = [];

        foreach ($suitePaths as $suite => $directories) {
            foreach ($directories as $directory) {
                if (self::isBelow($file, $directory)) {
                    $memberships[] = $suite;
                    break;
                }
            }
        }

        \sort($memberships, \SORT_STRING);

        return $memberships;
    }

    private static function isBelow(string $file, string $directory): bool
    {
        return \str_starts_with($file, \rtrim($directory, '/\\') . \DIRECTORY_SEPARATOR);
    }
}
