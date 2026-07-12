<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Support\AcceptanceProject;

/**
 * Worker channels through the real CLI.
 *
 * Every worker process carries GREENLIGHT_CHANNEL, a stable slot between 1
 * and the worker count, matched by the injectable TestChannel service. The
 * generated project echoes the variable and asserts the two agree, so a
 * passing exit code covers the service side and the captured output covers
 * the environment side.
 */
final readonly class ChannelTest
{
    #[Test]
    public function twoWorkersOccupyChannelsOneAndTwo(): void
    {
        $project = $this->writeProject();

        try {
            [$exit, $lines] = $project->runLines('run', '--workers=2', '--reporter=jsonl');
            $channels = $this->reportedChannels($lines);

            Expect::that($exit)->toBe(0)
                ->and(\count($channels))->toBe(4)
                ->and(\array_values(\array_unique($channels)))->toBe([1, 2]);
        } finally {
            $project->remove();
        }
    }

    #[Test]
    public function theInProcessRunnerIsChannelOne(): void
    {
        $project = $this->writeProject(expectedChannels: 1);

        try {
            [$exit, $lines] = $project->runLines('run', '--workers=1', '--reporter=jsonl');
            $channels = $this->reportedChannels($lines);

            Expect::that($exit)->toBe(0)
                ->and(\count($channels))->toBe(4)
                ->and(\array_values(\array_unique($channels)))->toBe([1]);
        } finally {
            $project->remove();
        }
    }

    #[Test]
    public function recycledWorkersReuseFreedChannels(): void
    {
        // Recycling after every test forces replacement workers; more
        // workers are spawned than channels exist, yet the occupied set
        // never leaves {1, 2}.
        $project = $this->writeProject(recycleAfterTests: 1);

        try {
            [$exit, $lines] = $project->runLines('run', '--reporter=jsonl');
            $channels = $this->reportedChannels($lines);

            Expect::that($exit)->toBe(0)
                ->and(\count($this->spawnedWorkers($lines)))->toBeGreaterThan(2)
                ->and(\count($channels))->toBe(4)
                ->and(\array_values(\array_unique($channels)))->toBe([1, 2]);
        } finally {
            $project->remove();
        }
    }

    /**
     * Channel numbers echoed by the generated tests, sorted ascending, read
     * from the captured stdout on test-finished events.
     *
     * @param list<string> $lines
     *
     * @return list<int>
     */
    private function reportedChannels(array $lines): array
    {
        $channels = [];

        foreach ($lines as $line) {
            $decoded = \json_decode($line, true);

            if (!\is_array($decoded) || ($decoded['event'] ?? null) !== 'test-finished') {
                continue;
            }

            $data = $decoded['data'] ?? null;
            $result = \is_array($data) && \is_array($data['result'] ?? null) ? $data['result'] : [];
            $output = \is_array($result['output'] ?? null) ? $result['output'] : [];
            $stdout = $output['stdout'] ?? null;

            if (\is_string($stdout) && \preg_match('/channel=(\d+)/', $stdout, $matches) === 1) {
                $channels[] = (int) $matches[1];
            }
        }

        \sort($channels);

        return $channels;
    }

    /**
     * @param list<string> $lines
     *
     * @return list<string>
     */
    private function spawnedWorkers(array $lines): array
    {
        $workers = [];

        foreach ($lines as $line) {
            $decoded = \json_decode($line, true);

            if (\is_array($decoded) && ($decoded['event'] ?? null) === 'worker-spawned'
                && \is_array($decoded['data'] ?? null) && \is_string($decoded['data']['workerId'] ?? null)) {
                $workers[] = $decoded['data']['workerId'];
            }
        }

        return $workers;
    }

    private function writeProject(?int $recycleAfterTests = null, int $expectedChannels = 2): AcceptanceProject
    {
        $project = AcceptanceProject::create('channel');
        $project->write('markers/.gitkeep', '');
        $markerDir = $project->path('markers');

        // Rather than hope a fixed sleep outlasts the time a second worker
        // needs to boot and claim a class, every class writes a marker for
        // its own channel and then waits until markers for every expected
        // channel exist. Neither worker can drain its whole queue before
        // the other has started, so the observed channel set is forced
        // deterministically instead of by timing luck.
        $template = <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace ChannelProbe;

            use Greenlight\Attribute\Test;
            use Greenlight\Core\Test\TestChannel;
            use Greenlight\Expect\Expect;

            final class %sTest
            {
                public function __construct(private readonly TestChannel $channel) {}

                #[Test]
                public function reportsItsChannel(): void
                {
                    $markerDir = %s;
                    \file_put_contents($markerDir . '/channel-' . $this->channel->number . '.started', '1');

                    $deadline = \microtime(true) + 10.0;

                    while (true) {
                        $markers = \glob($markerDir . '/channel-*.started') ?: [];

                        if (\count($markers) >= %d) {
                            break;
                        }

                        if (\microtime(true) >= $deadline) {
                            throw new \RuntimeException(\sprintf(
                                'Timed out after 10s waiting for %d distinct channel markers, found %%d: %%s',
                                \count($markers),
                                \implode(', ', $markers),
                            ));
                        }

                        \usleep(5_000);
                    }

                    echo 'channel=' . $this->channel->number;

                    Expect::that((string) $this->channel->number)->toBe(\getenv('GREENLIGHT_CHANNEL'))
                        ->and($this->channel->label())->toBe('gl-' . $this->channel->number);
                }
            }
            PHP;

        foreach (['Alpha', 'Bravo', 'Charlie', 'Delta'] as $name) {
            $project->write(\sprintf('tests/%sTest.php', $name), \sprintf(
                $template,
                $name,
                \var_export($markerDir, true),
                $expectedChannels,
                $expectedChannels,
            ));
        }

        $workers = $recycleAfterTests === null
            ? "->workers(2)"
            : \sprintf("->workers(2, recycleAfterTests: %d)", $recycleAfterTests);

        $project->write('greenlight.php', <<<PHP
            <?php

            declare(strict_types=1);

            use Greenlight\\Config\\GreenlightConfig;

            foreach (\\glob(__DIR__ . '/tests/*Test.php') ?: [] as \$file) {
                require_once \$file;
            }

            return GreenlightConfig::create()->paths([__DIR__ . '/tests']){$workers};
            PHP);

        return $project;
    }
}
