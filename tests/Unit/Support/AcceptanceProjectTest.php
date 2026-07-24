<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Support;

use Greenlight\Attribute\Test;
use Greenlight\Config\GreenlightConfig;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Tests\Support\AcceptanceProject;

final readonly class AcceptanceProjectTest
{
    public function __construct(private TempDirectory $workspace) {}

    #[Test]
    public function createsAProjectAndWritesNestedFiles(): void
    {
        $project = AcceptanceProject::create($this->workspace, 'project');
        $project->write('nested/example.txt', 'contents');

        Expect::that($project->directory)->toBe($this->workspace->path() . '/project')
            ->and($project->path('nested/example.txt'))->toBe($project->directory . '/nested/example.txt')
            ->and(\file_get_contents($project->path('nested/example.txt')))->toBe('contents');
    }

    #[Test]
    public function generatedConfigRequiresFilesAndBuildsTheRequestedRun(): void
    {
        $project = AcceptanceProject::create($this->workspace, 'configured');
        $project->write('tests/First.php', <<<'PHP'
            <?php

            file_put_contents(__DIR__ . '/../loaded.txt', 'first');
            PHP);
        $project->write('tests/Second.php', <<<'PHP'
            <?php

            file_put_contents(__DIR__ . '/../loaded.txt', 'second', FILE_APPEND);
            PHP);
        $project->writeConfig(['tests/First.php', 'tests/Second.php'], workers: 3);

        $builder = require $project->path('greenlight.php');

        if (!$builder instanceof GreenlightConfig) {
            throw new \RuntimeException('Expected generated config to return GreenlightConfig.');
        }

        $configuration = $builder->build();
        $testsDirectory = \realpath($project->path('tests'));

        if ($testsDirectory === false) {
            throw new \RuntimeException('Expected generated tests directory to exist.');
        }

        Expect::that(\file_get_contents($project->path('loaded.txt')))->toBe('firstsecond')
            ->and($configuration->paths)->toBe([$testsDirectory])
            ->and($configuration->workers->fixed)->toBe(3)
            ->and($configuration->randomizeOrder)->toBeFalse();
    }

    #[Test]
    public function listTestsProjectTargetsTheSharedDiscoveryFixture(): void
    {
        $project = AcceptanceProject::copyOfListTestsConfig($this->workspace, 'listing');
        $builder = require $project->path('greenlight.php');

        if (!$builder instanceof GreenlightConfig) {
            throw new \RuntimeException('Expected generated config to return GreenlightConfig.');
        }

        Expect::that($builder->build()->paths)->toBe([
            \dirname(__DIR__, 2) . '/Fixture/DiscoveryBasic',
        ]);
    }
}
