<?php

declare(strict_types=1);

namespace Greenlight\Cli\Command;

use Greenlight\Cli\Configuration\ConfigurationLoader;
use Greenlight\Cli\Input\Definition;
use Greenlight\Cli\Output\Console;
use Greenlight\Cli\Run\RunSession;
use Greenlight\Cli\Run\WorkerExecutable;
use Greenlight\Cli\Signal\SignalHandlers;
use Greenlight\Cli\State\RunState;
use Greenlight\Config\ConfigFileError;
use Greenlight\Config\StorageLayout;
use Greenlight\Internal\Php\ErrorTrap;
use Greenlight\Internal\Process\GracefulShutdown;
use Greenlight\Reporting\JsonLinesReporter;
use Greenlight\Reporting\StreamOutput;

/**
 * Runs one watch iteration in a fresh PHP process. Descriptor 3 carries
 * events independently of uncaptured test output on standard output.
 *
 * @internal
 */
final readonly class WatchRunCommand
{
    public function __construct(private Console $console) {}

    /** @param list<string> $argv */
    public function run(array $argv, string $workingDirectory, ?string $binPath): int
    {
        if (\function_exists('posix_setpgid')) {
            ErrorTrap::run(static fn() => \posix_setpgid(0, 0));
        }

        $events = ErrorTrap::run(static fn() => \fopen('php://fd/3', 'wb'));

        if ($events === false) {
            $this->console->err("Could not open the watch event stream.\n");

            return 1;
        }

        try {
            $priority = \json_decode($argv[0] ?? '', true, flags: \JSON_THROW_ON_ERROR);

            if (!\is_array($priority) || !\array_is_list($priority)) {
                throw new \InvalidArgumentException('Watch priority classes must be a list.');
            }

            $classes = [];

            foreach ($priority as $class) {
                if (!\is_string($class) || $class === '') {
                    throw new \InvalidArgumentException('Watch priority classes must be non-empty strings.');
                }

                $classes[] = $class;
            }

            $arguments = new Definition()->parser()->parse(\array_slice($argv, 1));
            $configuration = new ConfigurationLoader()->load($arguments, $workingDirectory);
            $resolved = $configuration->resolved;
            $storage = StorageLayout::resolve($resolved->storage, $workingDirectory, $resolved->suiteSelection->stateIdentity());
            $shutdown = new GracefulShutdown();
            SignalHandlers::install($shutdown);
            new RunSession(
                $this->console,
                $arguments,
                $configuration,
                $workingDirectory,
                WorkerExecutable::resolve($binPath),
                $shutdown,
                $resolved->selection,
                RunState::forFile($storage->runStateFile),
            )->watchAttempt(new JsonLinesReporter(new StreamOutput($events)), $classes);

            return 0;
        } catch (ConfigFileError $failure) {
            $this->console->error($failure->getMessage(), true);

            return 0;
        } catch (\Throwable $failure) {
            $this->console->error($failure->getMessage(), true);

            return 1;
        } finally {
            \fclose($events);
        }
    }
}
