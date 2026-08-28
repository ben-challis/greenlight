<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Coverage\CoverageMap;
use Greenlight\Coverage\FileCoverage;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\CoverageJson;
use Greenlight\Tests\Support\GreenlightCli;

final readonly class CoverageMergeErrorTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function requiresMultipleInputsAndOneOutput(): void
    {
        $directory = $this->tempDirectory->subdirectory('coverage-merge-required');
        CoverageJson::write($directory . '/one.json', CoverageMap::empty());

        $emptyInput = GreenlightCli::run($directory, [
            'coverage:merge',
            '--input=',
            '--input=one.json',
            '--export=json=merged.json',
        ]);
        Expect::that($emptyInput->exitCode)->toBe(64);
        Expect::that($emptyInput->output())
            ->toContain('--input requires a non-empty path.');

        $oneInput = GreenlightCli::run($directory, [
            'coverage:merge',
            '--input=one.json',
            '--export=json=merged.json',
        ]);
        Expect::that($oneInput->exitCode)->toBe(64);
        Expect::that($oneInput->output())
            ->toContain('coverage:merge requires at least two --input=<path> options.');

        $noOutput = GreenlightCli::run($directory, [
            'coverage:merge',
            '--input=one.json',
            '--input=one.json',
        ]);
        Expect::that($noOutput->exitCode)->toBe(64);
        Expect::that($noOutput->output())
            ->toContain('coverage:merge requires at least one --export=<format>=<path> option.');
    }

    #[Test]
    public function rejectsMalformedAndConflictingOutputs(): void
    {
        $directory = $this->tempDirectory->subdirectory('coverage-merge-output-options');
        CoverageJson::write($directory . '/one.json', CoverageMap::empty());

        $malformed = GreenlightCli::run($directory, [
            'coverage:merge',
            '--input=one.json',
            '--input=one.json',
            '--export=json',
        ]);
        Expect::that($malformed->exitCode)->toBe(64);
        Expect::that($malformed->output())
            ->toContain('--export requires <format>=<path>');

        $unknown = GreenlightCli::run($directory, [
            'coverage:merge',
            '--input=one.json',
            '--input=one.json',
            '--export=unknown=merged.out',
        ]);
        Expect::that($unknown->exitCode)->toBe(64);
        Expect::that($unknown->output())
            ->toContain('Unknown coverage export format "unknown"');

        $duplicate = GreenlightCli::run($directory, [
            'coverage:merge',
            '--input=one.json',
            '--input=one.json',
            '--export=json=merged.out',
            '--export=lcov=merged.out',
        ]);
        Expect::that($duplicate->exitCode)->toBe(64);
        Expect::that($duplicate->output())
            ->toContain('Write coverage export target "merged.out" only once.');
    }

    #[Test]
    public function namesUnreadableMalformedAndUnsupportedInputs(): void
    {
        $directory = $this->tempDirectory->subdirectory('coverage-merge-input-errors');
        CoverageJson::write($directory . '/valid.json', CoverageMap::empty());
        \file_put_contents($directory . '/malformed.json', '{');
        \file_put_contents($directory . '/version.json', '{"v":2,"files":{}}');

        $missing = GreenlightCli::run($directory, [
            'coverage:merge',
            '--input=missing.json',
            '--input=valid.json',
            '--export=json=merged.json',
        ]);
        Expect::that($missing->exitCode)->toBe(1);
        Expect::that($missing->output())
            ->toContain('Greenlight could not read coverage input "missing.json"');

        $malformed = GreenlightCli::run($directory, [
            'coverage:merge',
            '--input=malformed.json',
            '--input=valid.json',
            '--export=json=merged.json',
        ]);
        Expect::that($malformed->exitCode)->toBe(1);
        Expect::that($malformed->output())
            ->toContain('Coverage input "malformed.json" is not compatible')
            ->toContain('Coverage JSON document is invalid');

        $version = GreenlightCli::run($directory, [
            'coverage:merge',
            '--input=version.json',
            '--input=valid.json',
            '--export=json=merged.json',
        ]);
        Expect::that($version->exitCode)->toBe(1);
        Expect::that($version->output())
            ->toContain('Coverage input "version.json" is not compatible')
            ->toContain('unsupported or missing schema version');
    }

    #[Test]
    public function rejectsRelativeVersionOnePathsWithoutExplicitRootMapping(): void
    {
        $directory = $this->tempDirectory->subdirectory('coverage-merge-relative-path');
        \file_put_contents(
            $directory . '/relative.json',
            '{"v":1,"files":{"src/A.php":{"covered":[1],"uncovered":[]}}}',
        );
        CoverageJson::write($directory . '/valid.json', CoverageMap::empty());

        $result = GreenlightCli::run($directory, [
            'coverage:merge',
            '--input=relative.json',
            '--input=valid.json',
            '--export=json=merged.json',
        ]);

        Expect::that($result->exitCode)
            ->because('coverage JSON version 1 MUST keep absolute file paths')
            ->toBe(1);
        Expect::that($result->output())
            ->toContain('Coverage JSON version 1 requires an absolute file path. Received "src/A.php".');
    }

    #[Test]
    public function requiresCompleteCompatibleRootMetadata(): void
    {
        $directory = $this->tempDirectory->subdirectory('coverage-merge-root-errors');
        CoverageJson::write(
            $directory . '/one.json',
            new CoverageMap([new FileCoverage('/old/one/A.php', [1], [])]),
        );
        CoverageJson::write($directory . '/two.json', CoverageMap::empty());

        $missingInputRoots = GreenlightCli::run($directory, [
            'coverage:merge',
            '--input=one.json',
            '--input=two.json',
            '--project-root=/current',
            '--export=json=merged.json',
        ]);
        Expect::that($missingInputRoots->exitCode)->toBe(64);
        Expect::that($missingInputRoots->output())
            ->toContain('Use --input-root=<path> and --project-root=<path> together.');

        $missingProjectRoot = GreenlightCli::run($directory, [
            'coverage:merge',
            '--input=one.json',
            '--input=two.json',
            '--input-root=/old/one',
            '--input-root=/old/two',
            '--export=json=merged.json',
        ]);
        Expect::that($missingProjectRoot->exitCode)->toBe(64);
        Expect::that($missingProjectRoot->output())
            ->toContain('Use --input-root=<path> and --project-root=<path> together.');

        $partial = GreenlightCli::run($directory, [
            'coverage:merge',
            '--input=one.json',
            '--input=two.json',
            '--input-root=/old/one',
            '--project-root=/current',
            '--export=json=merged.json',
        ]);
        Expect::that($partial->exitCode)->toBe(64);
        Expect::that($partial->output())
            ->toContain('Repeat --input-root=<path> once for each --input=<path>.');

        $outside = GreenlightCli::run($directory, [
            'coverage:merge',
            '--input=one.json',
            '--input=two.json',
            '--input-root=/wrong',
            '--input-root=/old/two',
            '--project-root=/current',
            '--export=json=merged.json',
        ]);
        Expect::that($outside->exitCode)->toBe(1);
        Expect::that($outside->output())
            ->toContain('Coverage path "/old/one/A.php" is not below project root "/wrong".');

        $conflict = GreenlightCli::run($directory, [
            'coverage:merge',
            '--input=one.json',
            '--input=one.json',
            '--input-root=/old/one',
            '--input-root=/other',
            '--project-root=/current',
            '--export=json=merged.json',
        ]);
        Expect::that($conflict->exitCode)->toBe(64);
        Expect::that($conflict->output())
            ->toContain('Coverage input "one.json" cannot use more than one input root.');
    }

    #[Test]
    public function anUnreadableDestinationDoesNotReplaceIt(): void
    {
        $directory = $this->tempDirectory->subdirectory('coverage-merge-output-error');
        CoverageJson::write($directory . '/one.json', CoverageMap::empty());
        CoverageJson::write($directory . '/two.json', CoverageMap::empty());
        \mkdir($directory . '/coverage.json');

        $result = GreenlightCli::run($directory, [
            'coverage:merge',
            '--input=one.json',
            '--input=two.json',
            '--export=json=coverage.json',
            '--no-ansi',
        ]);

        Expect::that($result->exitCode)
            ->because('an invalid output destination MUST fail without replacing it')
            ->toBe(1);
        Expect::that($result->output())
            ->toContain('Greenlight could not write the coverage export');
        Expect::that(\is_dir($directory . '/coverage.json'))->toBeTrue();
    }

    #[Test]
    public function anUnsupportedExportPathFailsCleanly(): void
    {
        $directory = $this->tempDirectory->subdirectory('coverage-merge-export-error');
        \file_put_contents(
            $directory . '/invalid-path.json',
            '{"v":1,"files":{"/project/A\\nB.php":{"covered":[1],"uncovered":[]}}}',
        );
        CoverageJson::write($directory . '/valid.json', CoverageMap::empty());

        $result = GreenlightCli::run($directory, [
            'coverage:merge',
            '--input=invalid-path.json',
            '--input=valid.json',
            '--export=lcov=coverage.lcov',
            '--no-ansi',
        ]);

        Expect::that($result->exitCode)->toBe(1);
        Expect::that($result->output())
            ->toContain('Greenlight could not create the "lcov" coverage export')
            ->toContain('LCOV file paths MUST NOT contain line breaks.');
        Expect::that(\file_exists($directory . '/coverage.lcov'))->toBeFalse();
    }
}
