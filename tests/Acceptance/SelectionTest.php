<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;

/**
 * Drives --filter and --failed through the real CLI against a throwaway
 * project in a unique temp directory, so the run state keyed by that
 * directory cannot collide with other tests.
 */
final class SelectionTest
{
    #[Test]
    public function filterSelectsByMethodClassAndWildcard(): void
    {
        $project = $this->writeProject();

        try {
            $expect = new Expect();

            [$exit, $output] = $this->run($project, '--filter=alwaysPasses');
            $expect->that($exit)->toBe(0)->and($output)->toContain('Tests: 1, Passed: 1');

            [$exit, $output] = $this->run($project, '--filter=SelectionProbeTest');
            $expect->that($output)->toContain('Tests: 3,');

            [$exit, $output] = $this->run($project, '--filter=*::breaks?ometimes');
            $expect->that($exit)->toBe(1)->and($output)->toContain('Tests: 1, Passed: 0, Failed: 0, Errored: 1');

            [$exit, $output] = $this->run($project, '--filter=nothingMatchesThis');
            $expect->that($exit)->toBe(1)->and($output)->toContain('No tests found');
        } finally {
            $this->removeTree($project);
        }
    }

    #[Test]
    public function failedRerunsExactlyThePreviousFailures(): void
    {
        $project = $this->writeProject();

        try {
            $expect = new Expect();

            // --failed before any run is a usage error.
            [$exit, $output] = $this->run($project, '--failed');
            $expect->that($exit)->toBe(64)->and($output)->toContain('previous run');

            // A full run records one failure.
            [$exit] = $this->run($project);
            $expect->that($exit)->toBe(1);

            // --failed re-runs exactly that one test.
            [$exit, $output] = $this->run($project, '--failed');
            $expect->that($exit)->toBe(1)
                ->and($output)->toContain('Tests: 1, Passed: 0, Failed: 0, Errored: 1')
                ->and($output)->toContain('breaksSometimes');

            // A run where everything passes empties the state.
            [$exit] = $this->run($project, '--filter=alwaysPasses');
            $expect->that($exit)->toBe(0);

            [$exit, $output] = $this->run($project, '--failed');
            $expect->that($exit)->toBe(0)->and($output)->toContain('Nothing failed');
        } finally {
            $this->removeTree($project);
        }
    }

    /**
     * @return array{int, string}
     */
    private function run(string $project, string ...$flags): array
    {
        $root = \dirname(__DIR__, 2);
        $parts = [\escapeshellarg(\PHP_BINARY), \escapeshellarg($root . '/bin/greenlight'), 'run', '--reporter=plain'];

        foreach ($flags as $flag) {
            $parts[] = \escapeshellarg($flag);
        }

        $command = \sprintf('cd %s && %s 2>&1', \escapeshellarg($project), \implode(' ', $parts));
        \exec($command, $output, $exit);

        return [$exit, \implode("\n", $output)];
    }

    private function writeProject(): string
    {
        $project = \sys_get_temp_dir() . '/greenlight-selection-' . \bin2hex(\random_bytes(6));
        \mkdir($project . '/tests', 0o777, true);

        \file_put_contents($project . '/tests/SelectionProbeTest.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace SelectionProbe;

            use Greenlight\Attribute\Test;

            final class SelectionProbeTest
            {
                #[Test]
                public function alwaysPasses(): void {}

                #[Test]
                public function alsoPasses(): void {}

                #[Test]
                public function breaksSometimes(): never
                {
                    throw new \RuntimeException('intentional selection failure');
                }
            }
            PHP);

        \file_put_contents($project . '/greenlight.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Config\GreenlightConfig;

            require_once __DIR__ . '/tests/SelectionProbeTest.php';

            return GreenlightConfig::create()
                ->paths([__DIR__ . '/tests'])
                ->workers(1);
            PHP);

        return $project;
    }

    private function removeTree(string $directory): void
    {
        $files = \glob($directory . '/tests/*');

        foreach (\is_array($files) ? $files : [] as $file) {
            @\unlink($file);
        }

        @\unlink($directory . '/greenlight.php');
        @\rmdir($directory . '/tests');
        @\rmdir($directory);
    }
}
