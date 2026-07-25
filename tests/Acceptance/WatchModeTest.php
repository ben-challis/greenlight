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

            // A synthetic change: append a comment, size changes, mtime may not.
            $project->write('tests/WatchProbeTest.php', $original . "// touched\n");

            $output = $process->readStdoutUntil('Watching for changes', 20.0);
            Expect::that($output)->toContain('Change detected')
                ->toContain('1 test, 1 passed');

            $process->write('q');
            $result = $process->wait(10.0);
            $provisioned = \file($project->path('markers/provisioned.log'), \FILE_IGNORE_NEW_LINES);
            $cleaned = \file($project->path('markers/cleaned.log'), \FILE_IGNORE_NEW_LINES);

            Expect::that($result->exitCode)->toBe(0)
                ->and(\is_array($provisioned) ? $provisioned : [])->toHaveCount(2)
                ->and(\is_array($cleaned) ? $cleaned : [])->toBe(['cleaned', 'cleaned']);
        } finally {
            $process->terminate();
        }
    }

    private function writeProject(): AcceptanceProject
    {
        $project = AcceptanceProject::create($this->tempDirectory, 'watch');
        $project->write('markers/.gitkeep', '');
        $project->write('tests/WatchProbeTest.php', <<<'PHP'
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
        $markerDirectory = \var_export($project->path('markers'), true);
        $project->write('greenlight.php', <<<PHP
            <?php

            declare(strict_types=1);

            use Greenlight\Config\GreenlightConfig;
            use Greenlight\Tests\Fixture\Plugins\IntegrationProbePlugin;

            require_once __DIR__ . '/tests/WatchProbeTest.php';

            return GreenlightConfig::create()
                ->paths([__DIR__ . '/tests'])
                ->workers(1)
                ->watch(fn(\$watch) => \$watch->debounceMilliseconds(50))
                ->plugins(new IntegrationProbePlugin({$markerDirectory}));
            PHP);

        return $project;
    }
}
