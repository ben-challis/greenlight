<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;

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

        $command = \sprintf(
            'cd %s && %s -d disable_functions=proc_open %s run --workers=4 --reporter=plain 2>&1',
            \escapeshellarg($root . '/tests/Fixture/ListTestsConfig'),
            \escapeshellarg(\PHP_BINARY),
            \escapeshellarg($root . '/bin/greenlight'),
        );

        \exec($command, $output, $exit);
        $text = \implode("\n", $output);

        Expect::that($exit)->toBe(0)
            ->and($text)->toContain('Tests: 7, Passed: 7')
            ->and($text)->not()->toContain('proc_open');
    }
}
