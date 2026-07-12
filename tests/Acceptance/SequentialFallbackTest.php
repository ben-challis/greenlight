<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Support\AcceptanceProject;

/**
 * Worker spawning needs proc_open, which restricted hosts disable. The run
 * must then complete in-process instead of fataling, even when workers were
 * requested explicitly.
 */
final class SequentialFallbackTest
{
    #[Test]
    public function disabledProcOpenFallsBackToInProcess(): void
    {
        $root = \dirname(__DIR__, 2);
        // A private copy of ListTestsConfig, so this run cannot race another
        // acceptance test's use of the same working directory.
        $project = AcceptanceProject::copyOfListTestsConfig('sequential-fallback');

        try {
            $command = \sprintf(
                'cd %s && %s -d disable_functions=proc_open %s run --workers=4 --reporter=plain 2>&1',
                \escapeshellarg($project->directory),
                \escapeshellarg(\PHP_BINARY),
                \escapeshellarg($root . '/bin/greenlight'),
            );

            \exec($command, $output, $exit);
            $text = \implode("\n", $output);

            Expect::that($exit)->toBe(0)
                ->and($text)->toContain('7 tests, 7 passed')
                ->and($text)->not()->toContain('proc_open');
        } finally {
            $project->remove();
        }
    }
}
