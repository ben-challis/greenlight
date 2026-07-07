<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;

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
    public function __construct(
        private Expect $expect,
    ) {}

    #[Test]
    public function twoWorkersOccupyChannelsOneAndTwo(): void
    {
        $project = $this->writeProject();

        try {
            [$exit, $lines] = $this->run($project, 'run --workers=2 --reporter=jsonl');
            $channels = $this->reportedChannels($lines);

            $this->expect->that($exit)->toBe(0)
                ->and(\count($channels))->toBe(4)
                ->and(\array_values(\array_unique($channels)))->toBe([1, 2]);
        } finally {
            $this->removeTree($project);
        }
    }

    #[Test]
    public function theInProcessRunnerIsChannelOne(): void
    {
        $project = $this->writeProject();

        try {
            [$exit, $lines] = $this->run($project, 'run --workers=1 --reporter=jsonl');
            $channels = $this->reportedChannels($lines);

            $this->expect->that($exit)->toBe(0)
                ->and(\count($channels))->toBe(4)
                ->and(\array_values(\array_unique($channels)))->toBe([1]);
        } finally {
            $this->removeTree($project);
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
            [$exit, $lines] = $this->run($project, 'run --reporter=jsonl');
            $channels = $this->reportedChannels($lines);

            $this->expect->that($exit)->toBe(0)
                ->and(\count($this->spawnedWorkers($lines)))->toBeGreaterThan(2)
                ->and(\count($channels))->toBe(4)
                ->and(\array_values(\array_unique($channels)))->toBe([1, 2]);
        } finally {
            $this->removeTree($project);
        }
    }

    /**
     * @return array{int, list<string>}
     */
    private function run(string $project, string $arguments): array
    {
        $root = \dirname(__DIR__, 2);
        $command = \sprintf(
            'cd %s && %s %s %s 2>&1',
            \escapeshellarg($project),
            \escapeshellarg(\PHP_BINARY),
            \escapeshellarg($root . '/bin/greenlight'),
            $arguments,
        );
        \exec($command, $output, $exit);

        return [$exit, $output];
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

    private function writeProject(?int $recycleAfterTests = null): string
    {
        $project = \sys_get_temp_dir() . '/greenlight-channel-' . \bin2hex(\random_bytes(6));
        \mkdir($project . '/tests', 0o777, true);

        // The sleep keeps every class in flight long enough that both
        // workers take at least one class before the queue drains.
        $template = <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace ChannelProbe;

            use Greenlight\Attribute\Test;
            use Greenlight\Core\Test\TestChannel;
            use Greenlight\Expect\Expect;

            final class %sTest
            {
                public function __construct(
                    private readonly TestChannel $channel,
                    private readonly Expect $expect,
                ) {}

                #[Test]
                public function reportsItsChannel(): void
                {
                    \usleep(150_000);

                    echo 'channel=' . $this->channel->number;

                    $this->expect->that((string) $this->channel->number)->toBe(\getenv('GREENLIGHT_CHANNEL'))
                        ->and($this->channel->label())->toBe('gl-' . $this->channel->number);
                }
            }
            PHP;

        foreach (['Alpha', 'Bravo', 'Charlie', 'Delta'] as $name) {
            \file_put_contents($project . \sprintf('/tests/%sTest.php', $name), \sprintf($template, $name));
        }

        $workers = $recycleAfterTests === null
            ? "->workers(2)"
            : \sprintf("->workers(2, recycleAfterTests: %d)", $recycleAfterTests);

        \file_put_contents($project . '/greenlight.php', <<<PHP
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
