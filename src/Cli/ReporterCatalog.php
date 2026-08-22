<?php

declare(strict_types=1);

namespace Greenlight\Cli;

use Greenlight\Reporting\Output\Output;
use Greenlight\Reporting\Reporter;
use Greenlight\Reporting\ReporterDefinition;
use Greenlight\Reporting\ReporterProviderError;

/**
 * Stores the reporter factories for one command.
 *
 * @internal
 */
final readonly class ReporterCatalog
{
    /** @var array<non-empty-string, \Closure(Output): Reporter> */
    private array $factories;

    /**
     * @param list<ReporterDefinition> $definitions
     *
     * @throws ReporterProviderError
     */
    public function __construct(array $definitions)
    {
        $factories = [];

        foreach ($definitions as $definition) {
            if (isset($factories[$definition->name])) {
                throw ReporterProviderError::duplicateName($definition->name);
            }

            $factories[$definition->name] = $definition->factory;
        }

        $this->factories = $factories;
    }

    /** @return list<non-empty-string> */
    public function names(): array
    {
        return \array_keys($this->factories);
    }

    public function has(string $name): bool
    {
        return isset($this->factories[$name]);
    }

    /**
     * @throws CliError
     * @throws ReporterProviderError
     */
    public function create(string $name, Output $output): Reporter
    {
        $factory = $this->factories[$name] ?? null;

        if (!$factory instanceof \Closure) {
            throw CliError::unknownReporter($name, $this->names());
        }

        try {
            $reporter = $factory($output);
        } catch (\Throwable $error) {
            throw ReporterProviderError::factoryFailed($name, $error);
        }

        if (!$reporter instanceof Reporter) {
            throw ReporterProviderError::invalidReporter($name);
        }

        return $reporter;
    }
}
