<?php

declare(strict_types=1);

namespace Greenlight\Cli\Reporting;

use Greenlight\Cli\Input\CliError;
use Greenlight\Cli\Output\TerminalCapabilities;
use Greenlight\Internal\Php\ErrorTrap;
use Greenlight\Reporting\Reporter;

/**
 * Resolves reporter selections and owns their file output streams.
 *
 * A selection has the form <name> or <name>=<path>. Relative file paths use
 * the command working directory. The plan reuses each output for all runs in
 * one command.
 *
 * @internal
 */
final readonly class ReporterOutputPlan
{
    /**
     * @param list<array{name: non-empty-string, output: ReporterOutput}> $selections
     * @param list<ReporterOutput> $ownedOutputs
     */
    private function __construct(
        private array $selections,
        public ReporterOutput $standardOutput,
        private array $ownedOutputs,
    ) {}

    /**
     * @param list<string> $values
     * @param resource $stdout
     *
     * @throws CliError
     * @throws ReporterSetupFailed
     */
    public static function create(
        array $values,
        string $defaultName,
        ReporterCatalog $catalog,
        $stdout,
        string $workingDirectory,
        TerminalCapabilities $standardCapabilities,
        TerminalCapabilities $fileCapabilities,
    ): self {
        $values = $values === [] ? [$defaultName] : $values;
        $resolved = [];
        $targets = [];

        foreach ($values as $value) {
            [$name, $file] = self::parse($value);

            if (!$catalog->has($name)) {
                throw CliError::unknownReporter($name, $catalog->names());
            }

            $path = $file === null ? null : self::absolutePath($file, $workingDirectory);

            if ($path !== null && isset($targets[$path])) {
                throw CliError::duplicateReporterOutput($file);
            }

            if ($path !== null) {
                $targets[$path] = true;
            }

            $resolved[] = ['name' => $name, 'path' => $path];
        }

        $standardOutput = new ReporterOutput($stdout, $standardCapabilities, false);
        $selections = [];
        $ownedOutputs = [];

        try {
            foreach ($resolved as $selection) {
                $output = $selection['path'] === null
                    ? $standardOutput
                    : self::openFile($selection['path'], $fileCapabilities);

                if ($selection['path'] !== null) {
                    $ownedOutputs[] = $output;
                }

                $selections[] = ['name' => $selection['name'], 'output' => $output];
            }
        } catch (\Throwable $failure) {
            foreach ($ownedOutputs as $output) {
                $output->close();
            }

            throw $failure;
        }

        return new self($selections, $standardOutput, $ownedOutputs);
    }

    /**
     * @param list<string> $values
     *
     * @throws CliError
     */
    public static function selects(array $values, string $name): bool
    {
        return \array_any($values, fn($value) => self::parse($value)[0] === $name);
    }

    /**
     * @return list<Reporter>
     *
     * @throws CliError
     * @throws ReporterSetupFailed
     */
    public function createReporters(ReporterCatalog $catalog): array
    {
        $reporters = [];

        foreach ($this->selections as $selection) {
            $reporters[] = $catalog->create($selection['name'], $selection['output']);
        }

        return $reporters;
    }

    public function close(): void
    {
        foreach ($this->ownedOutputs as $output) {
            $output->close();
        }
    }

    public function writesReporterToStandardOutput(string $name): bool
    {
        return \array_any(
            $this->selections,
            fn($selection) => $selection['name'] === $name && $selection['output'] === $this->standardOutput,
        );
    }

    public function writesOnlyReportersToStandardOutput(string ...$names): bool
    {
        $selected = false;

        foreach ($this->selections as $selection) {
            if ($selection['output'] !== $this->standardOutput) {
                continue;
            }

            if (!\in_array($selection['name'], $names, true)) {
                return false;
            }

            $selected = true;
        }

        return $selected;
    }

    /**
     * @return array{non-empty-string, ?non-empty-string}
     *
     * @throws CliError
     */
    private static function parse(string $value): array
    {
        $separator = \strpos($value, '=');
        $name = $separator === false ? $value : \substr($value, 0, $separator);
        $file = $separator === false ? null : \substr($value, $separator + 1);

        if ($name === '' || $file === '') {
            throw CliError::malformedReporterSelection($value);
        }

        return [$name, $file];
    }

    /** @return non-empty-string */
    private static function absolutePath(string $path, string $workingDirectory): string
    {
        if (\str_starts_with($path, '/')) {
            return $path;
        }

        return \rtrim($workingDirectory, '/') . '/' . $path;
    }

    /** @throws ReporterSetupFailed */
    private static function openFile(string $path, TerminalCapabilities $capabilities): ReporterOutput
    {
        $directory = \dirname($path);

        if (!\is_dir($directory)) {
            try {
                $created = ErrorTrap::run(static fn() => \mkdir($directory, 0o777, true), $warning);
            } catch (\Throwable $failure) {
                throw ReporterSetupFailed::directoryCreationFailed($directory, $failure->getMessage(), $failure);
            }

            if (!$created && !\is_dir($directory)) {
                throw ReporterSetupFailed::directoryCreationFailed($directory, $warning);
            }
        }

        try {
            $stream = ErrorTrap::run(static fn() => \fopen($path, 'wb'), $warning);
        } catch (\Throwable $failure) {
            throw ReporterSetupFailed::fileOpenFailed($path, $failure->getMessage(), $failure);
        }

        if ($stream === false) {
            throw ReporterSetupFailed::fileOpenFailed($path, $warning);
        }

        return new ReporterOutput($stream, $capabilities, true);
    }
}
