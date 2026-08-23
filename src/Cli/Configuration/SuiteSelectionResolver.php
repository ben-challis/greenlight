<?php

declare(strict_types=1);

namespace Greenlight\Cli\Configuration;

use Greenlight\Cli\Input\CliError;
use Greenlight\Config\DiscoveryConfiguration;
use Greenlight\Config\SuiteSelection;

/**
 * Resolves suite selectors against the configured suite catalog.
 *
 * @internal
 */
final class SuiteSelectionResolver
{
    /** @codeCoverageIgnore */
    private function __construct() {}

    /**
     * @param list<non-empty-string> $names
     * @param list<non-empty-string> $tags
     *
     * @throws CliError
     */
    public static function resolve(DiscoveryConfiguration $configuration, array $names, array $tags): SuiteSelection
    {
        $explicit = $names !== [] || $tags !== [];

        if (!$explicit) {
            return new SuiteSelection([], [], $configuration->suites, false);
        }

        $names = self::unique($names);
        $tags = self::unique($tags);
        $availableNames = [];
        $availableTags = [];

        foreach ($configuration->suites as $suite) {
            $availableNames[$suite->name] = true;

            foreach ($suite->tags as $tag) {
                $availableTags[$tag] = true;
            }
        }

        $unknownNames = \array_values(\array_filter(
            $names,
            static fn(string $name): bool => !isset($availableNames[$name]),
        ));

        if ($unknownNames !== []) {
            throw CliError::unknownSuites($unknownNames);
        }

        $unknownTags = \array_values(\array_filter(
            $tags,
            static fn(string $tag): bool => !isset($availableTags[$tag]),
        ));

        if ($unknownTags !== []) {
            throw CliError::unknownSuiteTags($unknownTags);
        }

        $selected = [];

        foreach ($configuration->suites as $suite) {
            if (\in_array($suite->name, $names, true) || \array_intersect($suite->tags, $tags) !== []) {
                $selected[] = $suite;
            }
        }

        return new SuiteSelection($names, $tags, $selected, true);
    }

    /**
     * @param list<non-empty-string> $values
     * @return list<non-empty-string>
     */
    private static function unique(array $values): array
    {
        $unique = [];

        foreach ($values as $value) {
            if (!\in_array($value, $unique, true)) {
                $unique[] = $value;
            }
        }

        return $unique;
    }
}
