<?php

declare(strict_types=1);

namespace HyperfBridgeAcceptance\Cleanup;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;

use function Greenlight\expect;

final readonly class HyperfBlockedCoroutineRunTest
{
    #[Test]
    #[DataSet('lifetimes')]
    public function aBlockedCoroutineFailsAtTheTimeLimitAndTheWorkerContinues(string $lifetime): void
    {
        $root = \dirname(__DIR__, 2);
        $command = \implode(' ', \array_map(
            \escapeshellarg(...),
            [\PHP_BINARY, $root . '/vendor/bin/greenlight', 'run', '--config=blocked.php', '--no-ansi', '--reporter=plain'],
        ));
        $process = \proc_open(
            $command . ' 2>&1',
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w']],
            $pipes,
            $root,
            [...\getenv(), 'GREENLIGHT_HYPERF_CONTAINER_LIFETIME' => $lifetime],
        );

        if (!\is_resource($process)) {
            throw new \RuntimeException('The blocked coroutine run did not start.');
        }

        $output = \stream_get_contents($pipes[1]);
        \fclose($pipes[1]);
        $exitCode = \proc_close($process);

        if (!\is_string($output) || $output === '') {
            throw new \RuntimeException('The blocked coroutine run did not write output.');
        }

        expect(\substr_count($output, 'did not finish before the test time limit'))->because($output)->toBe(2);
        expect($output)->toContain('3 tests, 1 passed');
        expect($exitCode)->because($output)->toBe(1);
    }

    /** @return iterable<string, array{string}> */
    public static function lifetimes(): iterable
    {
        yield 'worker' => ['worker'];
        yield 'attempt' => ['attempt'];
    }
}
