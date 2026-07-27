<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Tests\Support\AcceptanceProject;
use Greenlight\Tests\Support\GreenlightCli;

final readonly class WatchModeTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function reRunsOnFileChangesAndQuitsOnQ(): void
    {
        $project = $this->writeProject();
        $watchedFile = $project->path('tests/WatchProbeTest.php');
        $original = \file_get_contents($watchedFile);

        if ($original === false) {
            throw new \RuntimeException('Could not read the watched fixture.');
        }

        $process = GreenlightCli::start($project->directory, ['run', '--watch', '--reporter=plain']);

        try {
            $output = $process->readStdoutUntil('Watching for changes', 20.0);
            Expect::that($output)->toContain('1 test, 1 passed');

            // Append a comment to make a synthetic change. The size changes, but
            // the modification time can remain equal.
            $project->writeFile('tests/WatchProbeTest.php', $original . "// touched\n");

            $output = $process->readStdoutUntil('Watching for changes', 20.0);
            Expect::that($output)->toContain('Change detected')
                ->toContain('1 test, 1 passed');

            $process->write('q');
            $result = $process->wait(10.0);
            Expect::that($result->exitCode)->toBe(0);
        } finally {
            $process->terminate();
        }
    }

    private function writeProject(): AcceptanceProject
    {
        $project = AcceptanceProject::create($this->tempDirectory, 'watch');
        $project->writeFile('tests/WatchProbeTest.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace WatchProbe;

            use Greenlight\Attribute\Test;

            final class WatchProbeTest
            {
                #[Test]
                public function passes(): void {}
            }
            PHP);
        $project->writeFile('greenlight.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Config\GreenlightConfig;

            require_once __DIR__ . '/tests/WatchProbeTest.php';

            return GreenlightConfig::create()
                ->paths([__DIR__ . '/tests'])
                ->workers(1)
                ->watch(fn($watch) => $watch->debounceMilliseconds(50));
            PHP);

        return $project;
    }
}
