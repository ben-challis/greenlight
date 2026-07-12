<?php

declare(strict_types=1);

namespace Greenlight\Config;

/**
 * A resolved named suite: a set of paths and the tags applied to every test
 * found under them.
 *
 * @internal
 */
final readonly class SuiteConfiguration
{
    /**
     * @param non-empty-string $name
     * @param non-empty-list<non-empty-string> $paths
     * @param list<non-empty-string> $tags
     */
    public function __construct(
        public string $name,
        public array $paths,
        public array $tags,
    ) {}
}
