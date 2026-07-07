<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;

/**
 * The profile through the real CLI.
 *
 * --profile appends the block after the summary, and profile:report
 * reproduces the same numbers offline from the jsonl artifact of the same
 * run.
 */
final class ProfileRunTest
{
    #[Test]
    public function liveProfileAndOfflineReportAgree(): void
    {
        $root = \dirname(__DIR__, 2);
        $fixture = $root . '/tests/Fixture/ListTestsConfig';
        $artifact = \sys_get_temp_dir() . '/greenlight-profile-' . \bin2hex(\random_bytes(6)) . '.jsonl';

        try {
            $command = \sprintf(
                'cd %s && %s %s run --workers=2 --reporter=plain --reporter=jsonl --profile 2>/dev/null',
                \escapeshellarg($fixture),
                \escapeshellarg(\PHP_BINARY),
                \escapeshellarg($root . '/bin/greenlight'),
            );
            \exec($command, $output, $exit);
            $live = \implode("\n", $output);

            $expect = new Expect();
            $expect->that($exit)->toBe(0)
                ->and($live)->toContain('Profile:')
                ->and($live)->toContain('spawned, 0 recycled')
                ->and($live)->toContain('Boot latency:')
                ->and($live)->toContain('Slowest classes:');

            // The jsonl lines are interleaved with the plain report on
            // stdout; extract them into an artifact file.
            $jsonl = \array_filter($output, static fn(string $line): bool => \str_starts_with($line, '{"v":'));
            \file_put_contents($artifact, \implode("\n", $jsonl) . "\n");

            // stdout only: extensions like ddtrace write noise to stderr on
            // spawn, and this comparison is exact.
            \exec(\sprintf(
                'cd %s && %s %s profile:report --input=%s 2>/dev/null',
                \escapeshellarg($root),
                \escapeshellarg(\PHP_BINARY),
                \escapeshellarg($root . '/bin/greenlight'),
                \escapeshellarg($artifact),
            ), $reportOutput, $reportExit);
            $offline = \implode("\n", $reportOutput);

            // The live block, minus its leading blank line, must reproduce
            // verbatim from the artifact.
            $liveBlock = \substr($live, (int) \strpos($live, 'Profile:'));

            $expect->that($reportExit)->toBe(0)
                ->and($offline . "\n")->toBe($liveBlock . "\n");
        } finally {
            @\unlink($artifact);
        }
    }
}
