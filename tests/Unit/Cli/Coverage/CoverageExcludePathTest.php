<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli\Coverage;

use Greenlight\Attribute\Test;
use Greenlight\Cli\Coverage\CoverageSession;
use Greenlight\Cli\Coverage\CoverageSettingsResolver;
use Greenlight\Cli\Coverage\ExecutableLineProbe;
use Greenlight\Config\CoverageBuilder;
use Greenlight\Coverage\Collection\CoverageSettings;
use Greenlight\Coverage\CoverageMap;
use Greenlight\Coverage\FileCoverage;
use Greenlight\Sandbox\TemporaryDirectory;

use function Greenlight\expect;

final readonly class CoverageExcludePathTest
{
    public function __construct(private TemporaryDirectory $temporaryDirectory) {}

    #[Test]
    public function anExcludedDirectoryLeavesTheReport(): void
    {
        $root = \realpath($this->temporaryDirectory->path());
        \assert(\is_string($root));
        \mkdir($root . '/src/Kept', 0o777, true);
        \mkdir($root . '/src/Skipped', 0o777, true);
        \mkdir($root . '/tmp');
        \file_put_contents($root . '/src/Kept/Loaded.php', "<?php\n\nreturn 1;\n");
        \file_put_contents($root . '/src/Kept/Unloaded.php', "<?php\n\nreturn 2;\n");
        \file_put_contents($root . '/src/Skipped/Loaded.php', "<?php\n\nreturn 3;\n");
        \file_put_contents($root . '/src/Skipped/Unloaded.php', "<?php\n\nreturn 4;\n");

        $configuration = new CoverageBuilder()->include('src')->exclude('src/Skipped')->toConfiguration();
        $settings = CoverageSettingsResolver::resolve($configuration, $root);
        \assert($settings instanceof CoverageSettings);

        $probe = new class implements ExecutableLineProbe {
            /** @var list<string> */
            public array $measured = [];

            #[\Override]
            public function measure(array $files): CoverageMap
            {
                $this->measured = [...$this->measured, ...$files];

                return new CoverageMap(\array_map(static fn(string $file): FileCoverage => new FileCoverage($file, [], [3]), $files));
            }
        };
        $session = CoverageSession::open($settings, false, $root . '/tmp', $probe);

        $coverage = $session->finish(new CoverageMap([
            new FileCoverage($root . '/src/Kept/Loaded.php', [3], []),
            new FileCoverage($root . '/src/Skipped/Loaded.php', [3], []),
        ]));
        $session->close();
        \assert($coverage instanceof CoverageMap);

        expect($probe->measured)
            ->because('the probe MUST NOT compile files in an excluded directory')
            ->toBe([$root . '/src/Kept/Unloaded.php']);
        expect(\array_keys($coverage->files()))
            ->because('an excluded directory MUST leave the report, also for loaded files')
            ->toBe([$root . '/src/Kept/Loaded.php', $root . '/src/Kept/Unloaded.php']);
    }
}
