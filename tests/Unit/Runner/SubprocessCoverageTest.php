<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner;

use Greenlight\Attribute\Test;
use Greenlight\Coverage\CoverageMap;
use Greenlight\Coverage\Export\JsonExporter;
use Greenlight\Coverage\FileCoverage;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\EnvironmentSandbox;
use Greenlight\Runner\CoverageSettings;
use Greenlight\Runner\SharedCoverageDirectory;
use Greenlight\Runner\SubprocessCoverage;

final class SubprocessCoverageTest
{
    #[Test]
    public function openExportsTheRelayVariablesAndDrainRestoresThem(): void
    {
        $sandbox = new EnvironmentSandbox();
        $sandbox->set(SubprocessCoverage::DIRECTORY_ENV, '/outer/dir');
        $sandbox->unset(SubprocessCoverage::INCLUDE_ENV);

        try {
            $shared = SharedCoverageDirectory::open(new CoverageSettings(['/project/src', '/project/lib']));

            $directory = \getenv(SubprocessCoverage::DIRECTORY_ENV);

            Expect::that(\is_string($directory) && $directory !== '/outer/dir')->toBeTrue()
                ->and(\is_string($directory) && \is_dir($directory))->toBeTrue()
                ->and(\getenv(SubprocessCoverage::INCLUDE_ENV))->toBe('/project/src' . \PATH_SEPARATOR . '/project/lib')
                ->and(SubprocessCoverage::requested())->toBeTrue();

            Expect::that($shared->drain())->toBeNull();

            Expect::that(\getenv(SubprocessCoverage::DIRECTORY_ENV))->toBe('/outer/dir')
                ->and(\getenv(SubprocessCoverage::INCLUDE_ENV))->toBeFalse()
                ->and(\is_string($directory) && \is_dir($directory))->toBeFalse();
        } finally {
            $sandbox->dispose();
        }
    }

    #[Test]
    public function drainMergesEveryDumpAndSkipsUnparseableOnes(): void
    {
        $sandbox = new EnvironmentSandbox();
        $sandbox->unset(SubprocessCoverage::DIRECTORY_ENV);
        $sandbox->unset(SubprocessCoverage::INCLUDE_ENV);

        try {
            $shared = SharedCoverageDirectory::open(new CoverageSettings([]));
            $directory = \getenv(SubprocessCoverage::DIRECTORY_ENV);

            if (!\is_string($directory)) {
                throw new \RuntimeException('The relay directory was not exported.');
            }

            $this->dump($directory, 'a.json', new CoverageMap([new FileCoverage('/app/a.php', [1, 2], [3])]));
            $this->dump($directory, 'b.json', new CoverageMap([new FileCoverage('/app/a.php', [3], []), new FileCoverage('/app/b.php', [7], [])]));
            \file_put_contents($directory . '/truncated.json', '{"v":1,"files":{');

            $merged = $shared->drain();

            if (!$merged instanceof CoverageMap) {
                throw new \RuntimeException('drain() returned no coverage.');
            }

            $files = $merged->files();

            Expect::that(\array_keys($files))->toBe(['/app/a.php', '/app/b.php'])
                ->and($files['/app/a.php']->coveredLines)->toBe([1, 2, 3])
                ->and($files['/app/a.php']->uncoveredLines)->toBe([])
                ->and($files['/app/b.php']->coveredLines)->toBe([7])
                ->and(\is_dir($directory))->toBeFalse();
        } finally {
            $sandbox->dispose();
        }
    }

    #[Test]
    public function beginDoesNothingWithoutTheRelayVariables(): void
    {
        $sandbox = new EnvironmentSandbox();
        $sandbox->unset(SubprocessCoverage::DIRECTORY_ENV);

        try {
            Expect::that(SubprocessCoverage::requested())->toBeFalse()
                ->and(SubprocessCoverage::begin())->toBeNull();
        } finally {
            $sandbox->dispose();
        }
    }

    private function dump(string $directory, string $name, CoverageMap $map): void
    {
        $export = new JsonExporter()->export($map);
        \file_put_contents($directory . '/' . $name, \reset($export));
    }
}
