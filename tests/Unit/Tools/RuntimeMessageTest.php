<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Tools;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Tests\Support\Subprocess;

final readonly class RuntimeMessageTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function coverageGateReportsAMissingExportExactly(): void
    {
        [$root, $script] = $this->toolSandbox('coverage-missing', 'coverage-gate.php');
        $summary = $root . '/summary.md';
        $result = Subprocess::run(
            $root,
            [\PHP_BINARY, $script],
            ['GITHUB_STEP_SUMMARY' => $summary],
        );

        Expect::that($result->exitCode)->toBe(1)
            ->and($result->stderr)->toContain(
                \sprintf(
                    'Coverage export not found at %s/build/coverage/coverage.json. Run `composer tests:coverage` first.',
                    (string) \realpath($root),
                ),
            )
            ->and((string) \file_get_contents($summary))->toBe(
                "## Code coverage\n\n"
                . "**Coverage unavailable.** The coverage run did not produce an export.\n\n",
            );
    }

    #[Test]
    public function coverageGateReportsASummaryWriteFailureExactly(): void
    {
        [$root, $script] = $this->toolSandbox('coverage-summary-write', 'coverage-gate.php');
        $summaryDirectory = $this->tempDirectory->subdirectory('coverage-summary-write/summary');
        $result = Subprocess::run(
            $root,
            [\PHP_BINARY, $script],
            ['GITHUB_STEP_SUMMARY' => $summaryDirectory],
        );

        Expect::that($result->exitCode)->toBe(1)
            ->and($result->stderr)->toContain(
                'Warning: Greenlight did not write the GitHub Actions job summary.',
            );
    }

    #[Test]
    public function phpStanExtractionReportsMissingAndExtractedSourcesExactly(): void
    {
        [$missingRoot, $missingScript] = $this->toolSandbox('phpstan-missing', 'extract-phpstan-api.php');
        $missing = Subprocess::run($missingRoot, [\PHP_BINARY, $missingScript]);

        Expect::that($missing->exitCode)->toBe(0)
            ->and($missing->stdout)->toBe(
                'The tool cannot extract the PHPStan API stubs because phpstan.phar is not installed.',
            );

        [$successRoot, $successScript] = $this->toolSandbox('phpstan-success', 'extract-phpstan-api.php');
        $pharPath = $successRoot . '/vendor/phpstan/phpstan/phpstan.phar';
        \mkdir(\dirname($pharPath), 0o777, true);
        $builder = Subprocess::run(
            $successRoot,
            [
                \PHP_BINARY,
                '-d',
                'phar.readonly=0',
                '-r',
                <<<'PHP'
                $phar = new Phar($argv[1]);
                $phar->startBuffering();
                $phar->addFromString('src/Fixture.php', "<?php\n");
                $phar->setStub("<?php __HALT_COMPILER();");
                $phar->stopBuffering();
                PHP,
                $pharPath,
            ],
        );

        Expect::that($builder->exitCode)->toBe(0);

        $success = Subprocess::run($successRoot, [\PHP_BINARY, $successScript]);
        $target = \realpath($successRoot) . '/.phpstan-api-stubs';

        Expect::that($success->exitCode)->toBe(0)
            ->and($success->stdout)->toBe(
                \sprintf(
                    'Greenlight extracted the PHPStan API sources to %s. Editors can index these sources.',
                    $target,
                ),
            )
            ->and(\is_file($target . '/src/Fixture.php'))->toBeTrue();
    }

    #[Test]
    public function proseCheckReportsExactDiagnosticsAndRejectsRemovedOptions(): void
    {
        $root = $this->tempDirectory->subdirectory('prose-check/project');
        \file_put_contents(
            $root . '/sample.md',
            "# Sample\n\n"
            . "The orchestrator collects every selected test class from the configured directories and sends one complete assignment "
            . "to each available worker before the test run starts in parallel.\n",
        );
        $script = $this->repositoryRoot() . '/tools/prose-check.php';
        $sentence = Subprocess::run($root, [
            \PHP_BINARY,
            $script,
            'check',
            '--root=' . $root,
        ]);

        Expect::that($sentence->exitCode)->toBe(1)
            ->and($sentence->stdout)->toContain(
                'sample.md:3: sentence-length: Write no more than 25 words in a descriptive sentence. Found 27 words.',
            );

        \file_put_contents($root . '/sample.md', "# Sample\n\nThe worker stops.\n");
        $removedOption = Subprocess::run($root, [
            \PHP_BINARY,
            $script,
            'check',
            '--root=' . $root,
            '--baseline-dir=' . $root . '/baseline',
        ]);

        Expect::that($removedOption->exitCode)->toBe(1)
            ->and($removedOption->stderr)->toContain('Unknown prose-check option "--baseline-dir=');
    }

    #[Test]
    public function memoryGateReportsMissingSamplesAndTheFinalMeasurementsExactly(): void
    {
        [$missingRoot, $missingScript] = $this->toolSandbox('memory-missing', 'memory-gate.php');
        $missing = Subprocess::run($missingRoot, [\PHP_BINARY, $missingScript]);

        Expect::that($missing->exitCode)->toBe(1)
            ->and($missing->stderr)->toContain(
                'The memory probe wrote no samples. The run did not reach the sample points.',
            );

        [$successRoot, $successScript] = $this->toolSandbox('memory-success', 'memory-gate.php');
        $binDirectory = $this->tempDirectory->subdirectory('memory-success/bin');
        \file_put_contents(
            $binDirectory . '/greenlight',
            <<<'PHP'
            <?php

            $configuration = (string) file_get_contents(getcwd() . '/greenlight.php');
            preg_match("~MemoryProbe\\('([^']+)'~", $configuration, $matches);
            file_put_contents(
                $matches[1],
                json_encode(['2000' => 1_048_576, '10000' => 1_572_864]),
            );
            echo "Synthetic memory probe completed.\n";
            PHP,
        );

        $success = Subprocess::run($successRoot, [\PHP_BINARY, $successScript]);

        Expect::that($success->exitCode)->toBe(0)
            ->and($success->stdout)->toContain(
                'Memory after 2000 tests: 1.00 MiB. Memory after 10000 tests: 1.50 MiB. '
                . 'Drift: +524288 bytes. Limit: 1048576 bytes.',
            );
    }

    #[Test]
    public function memoryGateReportsDirectoryCreationFailureExactly(): void
    {
        [$root, $script] = $this->toolSandbox('memory-directory', 'memory-gate.php');
        $blockedTemp = $root . '/not-a-directory';
        \file_put_contents($blockedTemp, 'blocked');
        $result = Subprocess::run($root, [
            \PHP_BINARY,
            '-d',
            'sys_temp_dir=' . $blockedTemp,
            $script,
        ]);

        Expect::that($result->exitCode)->toBe(255)
            ->and($result->stderr)->toContain('Greenlight did not create directory "')
            ->toContain('/suite".');
    }

    #[Test]
    public function benchmarkReportsDirectoryAndComposerFailuresExactly(): void
    {
        [$blockedRoot, $blockedScript] = $this->toolSandbox('benchmark-directory', 'benchmark.php');
        $blockedTemp = $blockedRoot . '/not-a-directory';
        \file_put_contents($blockedTemp, 'blocked');
        $directoryFailure = Subprocess::run($blockedRoot, [
            \PHP_BINARY,
            '-d',
            'sys_temp_dir=' . $blockedTemp,
            $blockedScript,
            '--shape=many-fast',
            '--scale=1',
            '--runs=1',
        ]);

        Expect::that($directoryFailure->exitCode)->toBe(1)
            ->and($directoryFailure->stderr)->toContain(
                'Greenlight did not create the benchmark project directory.',
            );

        $fakeBin = $this->tempDirectory->subdirectory('benchmark-composer/bin');
        \file_put_contents(
            $fakeBin . '/composer',
            "#!/bin/sh\nprintf 'fixture composer failure\\n'\nexit 12\n",
        );
        \chmod($fakeBin . '/composer', 0o700);
        $benchmarkTemp = $this->tempDirectory->subdirectory('benchmark-composer/temp');
        $path = \getenv('PATH');
        $composerFailure = Subprocess::run(
            $this->repositoryRoot(),
            [
                \PHP_BINARY,
                '-d',
                'sys_temp_dir=' . $benchmarkTemp,
                $this->repositoryRoot() . '/tools/benchmark.php',
                '--shape=giant-dataset',
                '--scale=1',
                '--workers=1',
                '--runs=1',
                '--with-phpunit',
            ],
            ['PATH' => $fakeBin . \PATH_SEPARATOR . (\is_string($path) ? $path : '')],
        );

        Expect::that($composerFailure->exitCode)->toBe(1)
            ->and($composerFailure->stderr)->toContain(
                "Composer did not install PHPUnit and ParaTest:\nfixture composer failure",
            );
    }

    /**
     * @return array{string, string}
     */
    private function toolSandbox(string $name, string $tool): array
    {
        $root = $this->tempDirectory->subdirectory($name);
        $tools = $this->tempDirectory->subdirectory($name . '/tools');
        $script = $tools . '/' . $tool;
        \copy($this->repositoryRoot() . '/tools/' . $tool, $script);

        return [$root, $script];
    }

    private function repositoryRoot(): string
    {
        return \dirname(__DIR__, 3);
    }
}
