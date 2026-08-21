<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\AcceptanceProject;
use Greenlight\Tests\Support\CoverageJson;
use Greenlight\Tests\Support\GreenlightCli;

final readonly class GeneratedCoveragePathTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function generatedSourceUnderAnInitiallyMissingAbsoluteIncludePathIsCollected(): void
    {
        $project = $this->writeProject();
        $generatedDirectory = $project->path('generated');

        Expect::that(\is_dir($generatedDirectory))
            ->because('the absolute coverage include path MUST be absent before the run')
            ->toBeFalse();

        $result = GreenlightCli::run(
            $project->directory,
            ['run', '--reporter=plain'],
            ['XDEBUG_MODE' => 'coverage'],
        );

        Expect::that($result->exitCode)
            ->because('coverage MUST collect source generated during the run')
            ->toBe(0);

        $generatedFile = CoverageJson::read($project->path('coverage.json'))
            ->files()[$generatedDirectory . '/RuntimeSource.php'] ?? null;

        Expect::that($generatedFile)
            ->because('The coverage export MUST contain generated/RuntimeSource.php.')
            ->not()
            ->toBeNull();
        Expect::that($generatedFile->coveredLines)
            ->because('the generated source MUST contain covered lines')
            ->not()
            ->toHaveCount(0);
    }

    private function writeProject(): AcceptanceProject
    {
        $project = AcceptanceProject::create($this->tempDirectory, 'generated-coverage-path');
        $project->writeFile('tests/GeneratesSourceTest.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace GeneratedCoverageProject;

            use Greenlight\Attribute\Test;
            use Greenlight\Expect\Expect;

            final class GeneratesSourceTest
            {
                #[Test]
                public function createsAndExecutesSource(): void
                {
                    $directory = \dirname(__DIR__) . '/generated';
                    \mkdir($directory, 0o777, true);
                    \file_put_contents($directory . '/RuntimeSource.php', <<<'SOURCE'
                        <?php

                        declare(strict_types=1);

                        namespace GeneratedCoverageProject;

                        function generatedValue(): int
                        {
                            return 42;
                        }
                        SOURCE);

                    require $directory . '/RuntimeSource.php';

                    Expect::that(generatedValue())->toBe(42);
                }
            }
            PHP);
        $project->writeFile('greenlight.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Config\GreenlightConfig;

            require_once __DIR__ . '/tests/GeneratesSourceTest.php';

            return GreenlightConfig::create()
                ->paths([__DIR__ . '/tests'])
                ->workers(1)
                ->coverage(fn($coverage) => $coverage
                    ->include(__DIR__ . '/generated')
                    ->export('json', 'coverage.json'));
            PHP);

        return $project;
    }
}
