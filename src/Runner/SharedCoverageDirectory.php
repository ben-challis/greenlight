<?php

declare(strict_types=1);

namespace Greenlight\Runner;

use Greenlight\Core\ErrorTrap;
use Greenlight\Coverage\CoverageError;
use Greenlight\Coverage\CoverageMap;
use Greenlight\Coverage\Export\JsonExporter;

/**
 * Exports a temporary coverage directory. drain() restores the previous
 * environment. It ignores corrupt or incomplete coverage files.
 *
 * @internal
 */
final readonly class SharedCoverageDirectory
{
    private function __construct(
        private string $directory,
        private string|false $previousDirectory,
        private string|false $previousInclude,
    ) {}

    public static function open(CoverageSettings $settings): self
    {
        $directory = \rtrim(\sys_get_temp_dir(), '/') . '/greenlight-coverage-' . \bin2hex(\random_bytes(6));
        ErrorTrap::run(static fn(): bool => \mkdir($directory, 0o700, true));

        $previousDirectory = \getenv(SubprocessCoverage::DIRECTORY_ENV);
        $previousInclude = \getenv(SubprocessCoverage::INCLUDE_ENV);

        \putenv(SubprocessCoverage::DIRECTORY_ENV . '=' . $directory);
        \putenv(SubprocessCoverage::INCLUDE_ENV . '=' . \implode(\PATH_SEPARATOR, $settings->includePaths));

        return new self($directory, $previousDirectory, $previousInclude);
    }

    public function drain(): ?CoverageMap
    {
        $this->restore(SubprocessCoverage::DIRECTORY_ENV, $this->previousDirectory);
        $this->restore(SubprocessCoverage::INCLUDE_ENV, $this->previousInclude);

        $dumps = \glob($this->directory . '/*.json');

        $map = ErrorTrap::run(static function () use ($dumps): ?CoverageMap {
            $map = null;

            foreach ($dumps === false ? [] : $dumps as $file) {
                $json = \file_get_contents($file);
                \unlink($file);

                if (!\is_string($json)) {
                    continue;
                }

                try {
                    $imported = JsonExporter::import($json);
                } catch (CoverageError) {
                    continue;
                }

                $map = $map instanceof CoverageMap ? $map->merge($imported) : $imported;
            }

            return $map;
        });

        ErrorTrap::run(fn(): bool => \rmdir($this->directory));

        return $map;
    }

    private function restore(string $name, string|false $value): void
    {
        \putenv($value === false ? $name : $name . '=' . $value);
    }
}
