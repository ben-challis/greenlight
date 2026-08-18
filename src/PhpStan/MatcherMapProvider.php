<?php

declare(strict_types=1);

namespace Greenlight\PhpStan;

use Greenlight\Config\ConfigFileError;
use Greenlight\Config\InvalidConfiguration;

/**
 * Loads one matcher map for all PHPStan expectation extensions.
 *
 * @internal
 */
final class MatcherMapProvider
{
    private ?MatcherMap $map = null;

    /**
     * @param list<string> $configFiles Relative paths use the directory from
     *   which PHPStan runs
     */
    public function __construct(private readonly array $configFiles) {}

    /**
     * @throws ConfigFileError
     * @throws InvalidConfiguration
     */
    public function get(): MatcherMap
    {
        return $this->map ??= MatcherMap::fromConfigFiles($this->configFiles);
    }
}
