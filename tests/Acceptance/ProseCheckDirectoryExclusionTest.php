<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Test\SkipTest;
use Greenlight\Tests\Support\PhpSubprocess;
use Greenlight\Tests\Support\ProjectFiles;

final readonly class ProseCheckDirectoryExclusionTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function doesNotOpenExcludedDirectories(): void
    {
        if (\function_exists('posix_getuid') && \posix_getuid() === 0) {
            throw new SkipTest('An unreadable directory cannot be staged when running as root.');
        }

        $project = ProjectFiles::create($this->tempDirectory, 'unreadable-exclusions');
        $project->write('README.md', "The worker stops.\n");
        $directories = ['vendor', 'packages/example/node_modules', 'build/cache', '.git'];

        foreach ($directories as $directory) {
            $project->write($directory . '/README.md', "Excluded content.\n");
            \chmod($project->path($directory), 0o000);
        }

        try {
            $result = PhpSubprocess::run($project->directory, [
                \dirname(__DIR__, 2) . '/tools/prose-check.php',
                'check',
                '--root=' . $project->directory,
            ]);

            Expect::that($result->exitCode)->because('excluded directories do not need read access')->toBe(0);
        } finally {
            foreach ($directories as $directory) {
                \chmod($project->path($directory), 0o755);
            }
        }
    }
}
