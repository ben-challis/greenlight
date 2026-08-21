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

    /** @throws CoverageError */
    public static function open(CoverageSettings $settings, ?string $temporaryDirectory = null): self
    {
        $temporaryDirectory ??= \sys_get_temp_dir();
        $directory = \rtrim($temporaryDirectory, '/') . '/greenlight-coverage-' . \bin2hex(\random_bytes(6));
        $created = ErrorTrap::run(static fn() => \mkdir($directory, 0o700, true), $warning);

        if (!$created) {
            throw CoverageError::sharedDirectoryCreationFailed($directory, $warning);
        }

        $previousDirectory = \getenv(SubprocessCoverage::DIRECTORY_ENV);
        $previousInclude = \getenv(SubprocessCoverage::INCLUDE_ENV);

        \putenv(SubprocessCoverage::DIRECTORY_ENV . '=' . $directory);
        \putenv(SubprocessCoverage::INCLUDE_ENV . '=' . CoverageRelayPaths::encode($settings->includePaths));

        return new self($directory, $previousDirectory, $previousInclude);
    }

    public function drain(): ?CoverageMap
    {
        $this->restore(SubprocessCoverage::DIRECTORY_ENV, $this->previousDirectory);
        $this->restore(SubprocessCoverage::INCLUDE_ENV, $this->previousInclude);

        $dumps = \glob($this->directory . '/*.json');

        $map = ErrorTrap::run(static function () use ($dumps) {
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

        ErrorTrap::run(fn() => \rmdir($this->directory));

        return $map;
    }

    private function restore(string $name, string|false $value): void
    {
        \putenv($value === false ? $name : $name . '=' . $value);
    }
}
