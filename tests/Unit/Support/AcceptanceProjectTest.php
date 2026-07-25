<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Support;

use Greenlight\Attribute\Test;
use Greenlight\Config\GreenlightConfig;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Tests\Support\AcceptanceProject;

final readonly class AcceptanceProjectTest
{
    public function __construct(private TempDirectory $workspace) {}

    #[Test]
    public function createsAProjectAndWritesNestedFiles(): void
    {
        $project = AcceptanceProject::create($this->workspace, 'project');
        $project->writeFile('nested/example.txt', 'contents');

        Expect::that($project->directory)->toBe($this->workspace->path() . '/project')
            ->and($project->path('nested/example.txt'))->toBe($project->directory . '/nested/example.txt')
            ->and(\file_get_contents($project->path('nested/example.txt')))->toBe('contents');
    }

    #[Test]
    public function configuresTheProjectWithTestFilesAndTheRequestedWorkerCount(): void
    {
        $project = AcceptanceProject::create($this->workspace, 'configured');
        $project->writeFile('tests/First.php', <<<'PHP'
            <?php

            file_put_contents(__DIR__ . '/../loaded.txt', 'first');
            PHP);
        $project->writeFile('tests/Second.php', <<<'PHP'
            <?php

            file_put_contents(__DIR__ . '/../loaded.txt', 'second', FILE_APPEND);
            PHP);
        $project->configureWithTestFiles(['tests/First.php', 'tests/Second.php'], workers: 3);

        $builder = require $project->path('greenlight.php');

        if (!$builder instanceof GreenlightConfig) {
            Fail::because(\sprintf(
                'Expected generated config "%s" to return GreenlightConfig, got %s.',
                $project->path('greenlight.php'),
                \get_debug_type($builder),
            ));
        }

        $configuration = $builder->build();
        $testsDirectory = \realpath($project->path('tests'));

        if ($testsDirectory === false) {
            Fail::because(\sprintf(
                'Expected generated tests directory at "%s".',
                $project->path('tests'),
            ));
        }

        Expect::that(\file_get_contents($project->path('loaded.txt')))->toBe('firstsecond')
            ->and($configuration->paths)->toBe([$testsDirectory])
            ->and($configuration->workers->fixed)->toBe(3)
            ->and($configuration->randomizeOrder)->toBeFalse();
    }

    #[Test]
    public function projectWithDiscoveryBasicTestsTargetsTheSharedFixture(): void
    {
        $project = AcceptanceProject::createWithDiscoveryBasicTests($this->workspace, 'listing');
        $builder = require $project->path('greenlight.php');

        if (!$builder instanceof GreenlightConfig) {
            Fail::because(\sprintf(
                'Expected generated config "%s" to return GreenlightConfig, got %s.',
                $project->path('greenlight.php'),
                \get_debug_type($builder),
            ));
        }

        Expect::that($builder->build()->paths)->toBe([
            \dirname(__DIR__, 2) . '/Fixture/DiscoveryBasic',
        ]);
    }
}
