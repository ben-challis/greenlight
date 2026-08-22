<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\AcceptanceProject;
use Greenlight\Tests\Support\GreenlightCli;

final readonly class StorageDirectoriesRunTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function configuredAreasReceiveTheirOwnedDataAcrossAWorkerProcess(): void
    {
        $project = AcceptanceProject::create($this->tempDirectory, 'storage-directories');
        $project->writeFile('greenlight.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Config\GreenlightConfig;
            use Greenlight\Config\StorageBuilder;

            require_once __DIR__ . '/tests/StorageProbeTest.php';

            return GreenlightConfig::create()
                ->paths([__DIR__ . '/tests'])
                ->workers(2)
                ->storage(static fn(StorageBuilder $storage) => $storage
                    ->stateDirectory(__DIR__ . '/storage/state')
                    ->cacheDirectory(__DIR__ . '/storage/cache')
                    ->generatedCodeDirectory(__DIR__ . '/storage/generated-code')
                    ->temporaryDirectory(__DIR__ . '/storage/temporary'));

            PHP);
        $project->writeFile('tests/StorageProbeTest.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace StorageProbe;

            use Greenlight\Attribute\Test;
            use Greenlight\Doubles\Doubles;
            use Greenlight\Expect\Expect;
            use Greenlight\Sandbox\TemporaryDirectory;

            interface Collaborator {}

            final readonly class StorageProbeTest
            {
                public function __construct(
                    private Doubles $doubles,
                    private TemporaryDirectory $temporaryDirectory,
                ) {}

                #[Test]
                public function usesWorkerStorage(): void
                {
                    $double = $this->doubles->spy(Collaborator::class);
                    file_put_contents(dirname(__DIR__) . '/observed-temporary-path.txt', $this->temporaryDirectory->path());

                    Expect::that($double)->toBeInstanceOf(Collaborator::class);
                }
            }

            PHP);

        $result = GreenlightCli::run($project->directory, ['run', '--no-ansi']);
        $discoveryFiles = \glob($project->path('storage/cache/greenlight-discovery-*.json'));
        $proxyFiles = \glob($project->path('storage/generated-code/*.php'));
        $temporaryPath = (string) \file_get_contents($project->path('observed-temporary-path.txt'));

        Expect::that($result->exitCode)
            ->because($result->output() === '' ? 'The configured storage run returned no output.' : $result->output())
            ->toBe(0);
        Expect::that(\is_file($project->path('storage/state/run-state.json')))
            ->because('run state MUST use its configured persistent directory')
            ->toBeTrue();
        Expect::that($discoveryFiles === false ? [] : $discoveryFiles)
            ->because('discovery metadata MUST use its configured data-cache directory')
            ->toHaveCount(1);
        Expect::that($proxyFiles === false ? [] : $proxyFiles)
            ->because('worker proxy PHP MUST use its configured generated-code directory')
            ->toHaveCount(1);
        Expect::that($temporaryPath)
            ->because('the worker temporary directory MUST use the configured runtime root')
            ->toStartWith($project->path('storage/temporary/greenlight-'));
        Expect::that(\is_dir($temporaryPath))
            ->because('test disposal MUST remove the owned temporary child')
            ->toBeFalse();
    }
}
