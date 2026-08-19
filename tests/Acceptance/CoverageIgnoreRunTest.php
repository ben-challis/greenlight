<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Tests\Support\AcceptanceProject;
use Greenlight\Tests\Support\CoverageJson;
use Greenlight\Tests\Support\GreenlightCli;

final readonly class CoverageIgnoreRunTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function ignoredLinesAreExcludedFromTotalsAndExports(): void
    {
        $project = $this->writeProject();
        $outDir = $project->path('coverage-out');
        $result = GreenlightCli::run(
            $project->directory,
            ['run', '--reporter=plain'],
            ['XDEBUG_MODE' => 'coverage'],
        );

        Expect::that($result->exitCode)->because('ignored lines are excluded from totals and exports')->toBe(0);
        Expect::that($result->output())->toContain('Coverage: 100.00%');

        $gadget = null;

        foreach (CoverageJson::read($outDir . '/coverage.json')->files() as $file => $coverage) {
            if (\str_ends_with($file, 'CoverageIgnoreLib/Gadget.php')) {
                $gadget = $coverage;
            }
        }

        if ($gadget === null) {
            Fail::because('Expected the coverage export to contain CoverageIgnoreLib/Gadget.php.');
        }

        Expect::that($gadget->uncoveredLines)->toBe([]);
        Expect::that($gadget->coveredLines)->not()->toHaveCount(0);
    }

    private function writeProject(): AcceptanceProject
    {
        $root = \dirname(__DIR__, 2);
        $project = AcceptanceProject::create($this->tempDirectory, 'coverage-ignore');
        $project->writeFile('greenlight.php', \sprintf(
            <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Config\GreenlightConfig;

            return GreenlightConfig::create()
                ->paths([%s])
                ->coverage(fn($coverage) => $coverage
                    ->include(%s)
                    ->export('json', 'coverage-out/coverage.json'));

            PHP,
            \var_export($root . '/tests/Fixture/CoverageIgnoreSuite', true),
            \var_export($root . '/tests/Fixture/CoverageIgnoreLib', true),
        ));

        return $project;
    }
}
