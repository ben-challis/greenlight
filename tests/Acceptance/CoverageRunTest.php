<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Runner\SubprocessCoverage;
use Greenlight\Tests\Support\AcceptanceProject;
use Greenlight\Tests\Support\GreenlightCli;
use Greenlight\Tests\Support\ProcessResult;

final readonly class CoverageRunTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function collectsAndExportsCoverageThroughTheProcessPool(): void
    {
        $project = $this->writeProject();
        $outDir = $project->path('coverage-out');
        $result = $this->runIn($project, ['run', '--workers=2', '--reporter=plain'], 'coverage');

        Expect::that($result->exitCode)->because('collects and exports coverage through the process pool')->toBe(0)
            ->and($result->output())->toContain('Coverage: 60.00% (3 of 5 lines)')
            ->toContain('  json → coverage-out/coverage.json');

        $json = \file_get_contents($outDir . '/coverage.json');

        if ($json === false) {
            Fail::because(\sprintf(
                'Expected a readable coverage JSON export at "%s".',
                $outDir . '/coverage.json',
            ));
        }

        /** @var array{files: array<string, array{covered: list<int>, uncovered: list<int>}>} $decoded */
        $decoded = \json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        $mathFile = null;

        foreach ($decoded['files'] as $file => $lines) {
            if (\str_ends_with($file, 'CoverageLib/Math.php')) {
                $mathFile = $lines;
            }
        }

        Expect::that($mathFile)->because('collects and exports coverage through the process pool')->not()->toBeNull()
            ->and($mathFile['covered'] ?? [])->not()->toHaveCount(0)
            ->and($mathFile['uncovered'] ?? [])->not()->toHaveCount(0);

        $lcov = \file_get_contents($outDir . '/lcov.info');

        Expect::that($lcov)->because('collects and exports coverage through the process pool')->toContain('SF:')
            ->toContain('end_of_record');
    }

    #[Test]
    public function missingDriverWarnsWithoutFailingTheRun(): void
    {
        $project = $this->writeProject();
        $result = $this->runIn($project, ['run', '--reporter=plain'], 'off');

        Expect::that($result->exitCode)->because('missing driver warns without failing the run')->toBe(0)
            ->and($result->output())->toContain('No worker collected the requested coverage')
            ->and(\is_dir($project->path('coverage-out')))->toBeFalse();
    }

    #[Test]
    public function unknownExportFormatFailsWithExactGuidance(): void
    {
        $project = $this->writeProject(exportFormat: 'sarif');
        $result = $this->runIn($project, ['run', '--reporter=plain'], 'coverage');

        Expect::that($result->exitCode)
            ->because('an unknown coverage export format MUST fail the run')
            ->toBe(1)
            ->and($result->output())
            ->toContain('Unknown coverage export format "sarif".')
            ->and(\is_dir($project->path('coverage-out')))
            ->toBeFalse();
    }

    #[Test]
    #[DataSet('xmlExportFormats')]
    public function configuredXmlExportFormatsWriteWellFormedDocuments(
        string $format,
        string $expectedChild,
    ): void {
        $project = $this->writeProject(exportFormat: $format);
        $result = $this->runIn($project, ['run', '--reporter=plain'], 'coverage');

        Expect::that($result->exitCode)
            ->because('a configured XML coverage export MUST complete')
            ->toBe(0)
            ->and($result->output())
            ->toContain(\sprintf('  %s → coverage-out/coverage.unknown', $format));

        $document = \file_get_contents($project->path('coverage-out/coverage.unknown'));

        if ($document === false) {
            Fail::because(\sprintf('Expected a readable %s coverage export.', $format));
        }

        $xml = new \SimpleXMLElement($document);
        $children = $xml->xpath('/coverage/' . $expectedChild);

        Expect::that($xml->getName())
            ->because('the configured XML exporter MUST write a coverage document')
            ->toBe('coverage')
            ->and($children === false ? [] : $children)
            ->not()
            ->toBe([]);
    }

    /**
     * @return iterable<string, array{non-empty-string, non-empty-string}>
     */
    public static function xmlExportFormats(): iterable
    {
        yield 'Clover' => ['clover', 'project'];
        yield 'Cobertura' => ['cobertura', 'packages'];
    }

    #[Test]
    public function failedSingleDocumentExportNamesTheTarget(): void
    {
        $project = $this->writeProject();
        $project->writeFile('coverage-out', 'not a directory');
        $result = $this->runIn($project, ['run', '--reporter=plain'], 'coverage');

        Expect::that($result->exitCode)
            ->because('a failed coverage export MUST fail the run')
            ->toBe(1)
            ->and($result->output())
            ->toContain('Greenlight could not write the coverage export to')
            ->toContain('coverage-out/coverage.json')
            ->not()
            ->toContain('json → coverage-out/coverage.json');
    }

    #[Test]
    public function missingDriverFailsWhenAPerTestArtifactWasRequested(): void
    {
        $project = $this->writeProject();
        $root = \dirname(__DIR__, 2);
        $result = $this->runIn($project, [
            'run',
            '--workers=1',
            '--reporter=plain',
            '--coverage-map=coverage-out/test-map.jsonl',
            '--coverage-include=' . $root . '/tests/Fixture/CoverageLib',
        ], 'off');

        Expect::that($result->exitCode)->toBe(1)
            ->and($result->output())->toContain('Per-test coverage was requested but cannot be collected')
            ->and(\is_file($project->path('coverage-out/test-map.jsonl')))->toBeFalse();
    }

    #[Test]
    public function exportsPerTestCoverageThroughTheProcessPool(): void
    {
        $project = $this->writeProject();
        $root = \dirname(__DIR__, 2);
        $result = $this->runIn($project, [
            'run',
            '--workers=2',
            '--reporter=plain',
            '--coverage-map=coverage-out/test-map.jsonl',
            '--coverage-include=' . $root . '/tests/Fixture/CoverageLib',
        ], 'coverage');

        Expect::that($result->exitCode)->toBe(0);

        $lines = \file(
            $project->path('coverage-out/test-map.jsonl'),
            \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES,
        );

        if (!\is_array($lines)) {
            Fail::because('Expected a readable per-test coverage artifact.');
        }

        $records = [];

        foreach ($lines as $line) {
            $record = \json_decode($line, true, flags: \JSON_THROW_ON_ERROR);

            if (!\is_array($record)) {
                Fail::because('Expected every per-test coverage line to decode to an object.');
            }

            $records[] = $record;
        }
        $tests = \array_values(\array_filter(
            $records,
            static fn(array $record): bool => ($record['type'] ?? null) === 'test',
        ));
        $mapping = \array_values(\array_filter(
            $records,
            static function (array $record): bool {
                $file = $record['file'] ?? null;

                return ($record['type'] ?? null) === 'coverage'
                    && \is_string($file)
                    && \str_ends_with($file, 'CoverageLib/Math.php');
            },
        ));

        Expect::that($tests)->toHaveCount(1)
            ->and($tests[0]['renderedId'])->toBe('Greenlight\Tests\Fixture\CoverageSuite\MathTest::addsTwoIntegers')
            ->and($mapping)->not()->toBeEmpty();
    }

    #[Test]
    public function orchestratorProcessCoverageIsMergedIntoTheExport(): void
    {
        $project = $this->writeProject(includeOrchestrator: true);
        $outDir = $project->path('coverage-out');

        // A relay environment from an outer coverage run excludes orchestrator
        // coverage from this run. Clear it to make this run independent.
        $result = $this->runIn($project, ['run', '--workers=2', '--reporter=plain'], 'coverage', [
            SubprocessCoverage::DIRECTORY_ENV => '',
            SubprocessCoverage::INCLUDE_ENV => '',
        ]);

        Expect::that($result->exitCode)->because('orchestrator process coverage is merged into the export')->toBe(0)
            ->and($result->output())->toContain('  json → coverage-out/coverage.json');

        $json = \file_get_contents($outDir . '/coverage.json');

        if ($json === false) {
            Fail::because(\sprintf(
                'Expected a readable coverage JSON export at "%s".',
                $outDir . '/coverage.json',
            ));
        }

        /** @var array{files: array<string, array{covered: list<int>}>} $decoded */
        $decoded = \json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        $orchestratorFile = null;

        foreach ($decoded['files'] as $file => $lines) {
            if (\str_ends_with($file, 'src/Runner/Orchestrator/Orchestrator.php')) {
                $orchestratorFile = $lines;
            }
        }

        // Only the orchestrator process loads Orchestrator.php. Thus, covered
        // lines in that file show orchestrator coverage collection.
        Expect::that($orchestratorFile)->because('orchestrator process coverage is merged into the export')->not()->toBeNull()
            ->and($orchestratorFile['covered'] ?? [])->not()->toHaveCount(0);
    }

    #[Test]
    public function spawnedCliProcessesDumpCoverageIntoTheSharedDirectory(): void
    {
        $root = \dirname(__DIR__, 2);
        $shared = $this->tempDirectory->subdirectory('coverage-relay');
        $project = $this->writeProject();

        $result = $this->runIn($project, ['run', '--workers=2', '--reporter=plain'], 'coverage', [
            SubprocessCoverage::DIRECTORY_ENV => $shared,
            SubprocessCoverage::INCLUDE_ENV => $root . '/src/Cli',
        ]);

        Expect::that($result->exitCode)->because('spawned CLI processes dump coverage into the shared directory')->toBe(0);

        $dumps = \glob($shared . '/*.json');
        $dumps = $dumps === false ? [] : $dumps;

        Expect::that($dumps)->because('spawned CLI processes dump coverage into the shared directory')->not()->toHaveCount(0);

        $contents = $dumps === [] ? '' : (string) \file_get_contents($dumps[0]);

        Expect::that($contents)->because('spawned CLI processes dump coverage into the shared directory')->toContain('src/Cli/Application.php');
    }

    #[Test]
    public function failedMultiFileExportNamesTheFirstTarget(): void
    {
        $project = $this->writeProject(exportFormat: 'html');
        $project->writeFile('coverage-out/coverage.unknown', 'not a directory');
        $result = $this->runIn($project, ['run', '--reporter=plain'], 'coverage');

        Expect::that($result->exitCode)
            ->because('a failed multi-file coverage export MUST fail the run')
            ->toBe(1)
            ->and($result->output())
            ->toContain('Greenlight could not write the coverage export to')
            ->toContain('coverage-out/coverage.unknown/index.html')
            ->not()
            ->toContain('html → coverage-out/coverage.unknown');
    }

    #[Test]
    public function coverageDiffFailsOnRegressionsAndPassesWhenEqual(): void
    {
        $project = $this->writeProject();
        $outDir = $project->path('coverage-out');

        $result = $this->runIn($project, ['run', '--reporter=plain'], 'coverage');
        Expect::that($result->exitCode)->because('coverage diff fails on regressions and passes when equal')->toBe(0);

        $baseline = $outDir . '/coverage.json';

        $sameResult = GreenlightCli::run(
            $project->directory,
            ['coverage:diff', '--baseline=coverage-out/coverage.json', '--current=coverage-out/coverage.json'],
        );

        Expect::that($sameResult->exitCode)->because('coverage diff fails on regressions and passes when equal')->toBe(0)
            ->and($sameResult->output())->toContain('(+0.00)');

        $json = \file_get_contents($baseline);

        if ($json === false) {
            Fail::because(\sprintf(
                'Expected a readable baseline coverage export at "%s".',
                $baseline,
            ));
        }

        /** @var array{files: array<string, array{covered: list<int>, uncovered: list<int>}>} $decoded */
        $decoded = \json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        $mathFile = null;

        foreach (\array_keys($decoded['files']) as $file) {
            if (\str_ends_with($file, 'CoverageLib/Math.php')) {
                $mathFile = $file;
            }
        }

        if ($mathFile === null) {
            Fail::because('Baseline export has no entry for CoverageLib/Math.php.');
        }

        $before = $decoded['files'][$mathFile];

        // Make a regressed current export. Move one covered line to uncovered.
        $movedLine = $before['covered'][0];
        $decoded['files'][$mathFile]['covered'] = \array_values(\array_diff($before['covered'], [$movedLine]));
        $decoded['files'][$mathFile]['uncovered'] = [...$before['uncovered'], $movedLine];

        Expect::that($decoded['files'][$mathFile])->because('coverage diff fails on regressions and passes when equal')->not()->toBe($before);

        $regressed = \json_encode($decoded, \JSON_THROW_ON_ERROR);
        $regressedPath = $outDir . '/regressed.json';
        \file_put_contents($regressedPath, $regressed);

        $regressedResult = GreenlightCli::run(
            $project->directory,
            ['coverage:diff', '--baseline=coverage-out/coverage.json', '--current=coverage-out/regressed.json'],
        );

        Expect::that($regressedResult->exitCode)->because('coverage diff fails on regressions and passes when equal')->toBe(1)
            ->and($regressedResult->output())->toContain('Coverage regressed against the baseline.')
            ->toContain('newly uncovered lines: ' . $movedLine);
    }

    /**
     * @param list<string> $arguments
     * @param array<string, string> $extraEnv
     */
    private function runIn(AcceptanceProject $project, array $arguments, string $xdebugMode, array $extraEnv = []): ProcessResult
    {
        return GreenlightCli::run(
            $project->directory,
            $arguments,
            ['XDEBUG_MODE' => $xdebugMode, ...$extraEnv],
        );
    }

    private function writeProject(
        bool $includeOrchestrator = false,
        ?string $exportFormat = null,
    ): AcceptanceProject {
        $root = \dirname(__DIR__, 2);
        $project = AcceptanceProject::create($this->tempDirectory, 'coverage');
        $orchestratorInclude = $includeOrchestrator
            ? \sprintf("\n        ->include(%s)", \var_export($root . '/src/Runner/Orchestrator', true))
            : '';
        $exports = $exportFormat === null
            ? "\n        ->export('json', 'coverage-out/coverage.json')"
                . "\n        ->export('lcov', 'coverage-out/lcov.info')"
            : \sprintf(
                "\n        ->export(%s, 'coverage-out/coverage.unknown')",
                \var_export($exportFormat, true),
            );

        $project->writeFile('greenlight.php', \sprintf(
            <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Config\GreenlightConfig;

            return GreenlightConfig::create()
                ->paths([%s])
                ->coverage(fn($coverage) => $coverage
                    ->include(%s)%s%s);

            PHP,
            \var_export($root . '/tests/Fixture/CoverageSuite', true),
            \var_export($root . '/tests/Fixture/CoverageLib', true),
            $orchestratorInclude,
            $exports,
        ));

        return $project;
    }
}
