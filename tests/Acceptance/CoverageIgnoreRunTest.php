<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\AcceptanceProject;
use Greenlight\Tests\Support\CoverageJson;
use Greenlight\Tests\Support\FixturePath;
use Greenlight\Tests\Support\GreenlightCli;

final readonly class CoverageIgnoreRunTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

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

        Expect::that($gadget)
            ->because('The coverage export MUST contain CoverageIgnoreLib/Gadget.php.')
            ->not()
            ->toBeNull();
        Expect::that($gadget->uncoveredLines)->toBe([]);
        Expect::that($gadget->coveredLines)->not()->toHaveCount(0);
    }

    private function writeProject(): AcceptanceProject
    {
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
            \var_export(FixturePath::get('CoverageIgnoreSuite'), true),
            \var_export(FixturePath::get('CoverageIgnoreLib'), true),
        ));

        return $project;
    }
}
