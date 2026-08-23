<?php

declare(strict_types=1);

namespace Greenlight\InfectionAdapter;

use Greenlight\Cli\Application;
use Infection\AbstractTestFramework\Coverage\TestLocation;
use Infection\AbstractTestFramework\TestFrameworkAdapter;

final readonly class GreenlightAdapter implements TestFrameworkAdapter
{
    private const string COVERAGE_DIRECTORY = 'coverage-xml';
    private const string COVERAGE_MAP = 'greenlight-test-coverage.jsonl';

    /** @param list<string> $sourceDirectories */
    public function __construct(
        private string $executable,
        private string $temporaryDirectory,
        private string $configuration,
        private string $projectDirectory,
        private array $sourceDirectories,
    ) {}

    public function getName(): string
    {
        return 'Greenlight';
    }

    public function testsPass(string $output): bool
    {
        // Infection also requires a zero process exit code. Greenlight's exit
        // code is authoritative. Human-readable output is not an API.
        return true;
    }

    public function hasJUnitReport(): bool
    {
        return false;
    }

    /** @param array<string> $phpExtraArgs @return list<string> */
    public function getInitialTestRunCommandLine(
        string $extraOptions,
        array $phpExtraArgs,
        bool $skipCoverage,
    ): array {
        $arguments = [
            'run',
            '--config=' . $this->configuration,
            '--no-ansi',
            '--reporter=plain',
            ...$this->extraOptions($extraOptions),
        ];

        if (!$skipCoverage) {
            $arguments[] = '--coverage-map=' . $this->temporaryDirectory . '/' . self::COVERAGE_MAP;

            foreach ($this->sourceDirectories as $directory) {
                $arguments[] = '--coverage-include=' . $this->absoluteSourceDirectory($directory);
            }

            $arguments[] = '--infection-coverage-xml=' . $this->temporaryDirectory . '/' . self::COVERAGE_DIRECTORY;
        } else {
            $arguments[] = '--no-coverage';
        }

        return [
            \PHP_BINARY,
            ...\array_values($phpExtraArgs),
            $this->executable,
            ...$arguments,
        ];
    }

    /** @param array<TestLocation> $coverageTests @return list<string> */
    public function getMutantCommandLine(
        array $coverageTests,
        string $mutatedFilePath,
        string $mutationHash,
        string $mutationOriginalFilePath,
        string $extraOptions,
    ): array {
        $ids = [];

        foreach ($coverageTests as $test) {
            $id = $test->getMethod();

            if (\str_contains($id, "\n") || \str_contains($id, "\r")) {
                throw new \RuntimeException(\sprintf(
                    'Greenlight test ID "%s" cannot be written to the exact-test file because it contains a line break.',
                    $id,
                ));
            }

            $ids[$id] = true;
        }

        if ($ids === []) {
            return [\PHP_BINARY, $this->executable, '--infection-no-tests'];
        }

        $testFile = $this->temporaryDirectory . '/greenlight-tests-' . \hash('sha256', $mutationHash) . '.txt';
        $bytes = \file_put_contents($testFile, \implode("\n", \array_keys($ids)) . "\n", \LOCK_EX);

        if ($bytes === false) {
            throw new \RuntimeException(\sprintf('Could not write Greenlight exact-test file "%s".', $testFile));
        }

        return [
            \PHP_BINARY,
            $this->executable,
            'run',
            '--config=' . $this->configuration,
            '--no-ansi',
            '--reporter=plain',
            '--no-coverage',
            '--test-id-file=' . $testFile,
            '--infection-original=' . $mutationOriginalFilePath,
            '--infection-mutant=' . $mutatedFilePath,
            ...$this->extraOptions($extraOptions),
        ];
    }

    public function getVersion(): string
    {
        return Application::VERSION;
    }

    public function getInitialTestsFailRecommendations(string $commandLine): string
    {
        return \sprintf('Run the generated Greenlight command directly to inspect the failure: %s', $commandLine);
    }

    /** @return list<string> */
    private function extraOptions(string $extraOptions): array
    {
        if (\trim($extraOptions) === '') {
            return [];
        }

        $options = \str_getcsv($extraOptions, ' ', '"', '\\');
        $arguments = [];

        foreach ($options as $option) {
            if (\is_string($option) && $option !== '') {
                $arguments[] = $option;
            }
        }

        return $arguments;
    }

    private function absoluteSourceDirectory(string $directory): string
    {
        if (\str_starts_with($directory, '/')) {
            return $directory;
        }

        return \rtrim($this->projectDirectory, '/') . '/' . $directory;
    }
}
