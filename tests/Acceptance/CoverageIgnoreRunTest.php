<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Tests\Support\AcceptanceProject;
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

        Expect::that($result->exitCode)->toBe(0)
            ->and($result->output())->toContain('Coverage: 100.00%');

        $json = \file_get_contents($outDir . '/coverage.json');

        if ($json === false) {
            Fail::because(\sprintf(
                'Expected a readable coverage JSON export at "%s".',
                $outDir . '/coverage.json',
            ));
        }

        /** @var array{files: array<string, array{covered: list<int>, uncovered: list<int>}>} $decoded */
        $decoded = \json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        $gadget = null;

        foreach ($decoded['files'] as $file => $lines) {
            if (\str_ends_with($file, 'CoverageIgnoreLib/Gadget.php')) {
                $gadget = $lines;
            }
        }

        Expect::that($gadget)->not()->toBeNull()
            ->and($gadget['uncovered'] ?? null)->toBe([])
            ->and($gadget['covered'] ?? [])->not()->toHaveCount(0);
    }

    private function writeProject(): AcceptanceProject
    {
        $root = \dirname(__DIR__, 2);
        $project = AcceptanceProject::create($this->tempDirectory, 'coverage-ignore');
        $project->write('greenlight.php', \sprintf(
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
