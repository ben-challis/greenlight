<?php

declare(strict_types=1);

namespace Greenlight\Cli\Configuration;

use Greenlight\Config\ResolvedConfiguration;

/**
 * Contains one resolved CLI configuration and its resolved paths.
 *
 * @internal
 */
final readonly class LoadedConfiguration
{
    /** @param list<non-empty-string> $directories */
    public function __construct(
        public ResolvedConfiguration $resolved,
        public string $file,
        public CliOverrides $overrides,
        public array $directories,
    ) {}
}
