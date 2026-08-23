<?php

declare(strict_types=1);

namespace Greenlight\Config;

/**
 * Contains one canonical set of suites for a resolved command.
 *
 * @internal
 */
final readonly class SuiteSelection
{
    /**
     * @param list<non-empty-string> $names
     * @param list<non-empty-string> $tags
     * @param list<SuiteConfiguration> $suites
     */
    public function __construct(
        public array $names,
        public array $tags,
        public array $suites,
        public bool $explicit,
    ) {}

    /** @return list<non-empty-string> */
    public function paths(DiscoveryConfiguration $configuration): array
    {
        $paths = $this->explicit ? [] : $configuration->paths;

        foreach ($this->suites as $suite) {
            foreach ($suite->paths as $path) {
                if (!\in_array($path, $paths, true)) {
                    $paths[] = $path;
                }
            }
        }

        return $paths;
    }

    /**
     * Returns null for the compatibility selection that includes all paths.
     *
     * @return non-empty-string|null
     */
    public function stateIdentity(): ?string
    {
        if (!$this->explicit) {
            return null;
        }

        $names = \array_map(static fn(SuiteConfiguration $suite): string => $suite->name, $this->suites);
        \sort($names, \SORT_STRING);

        return 'suites-' . \substr(\sha1(\implode("\n", $names)), 0, 12);
    }
}
