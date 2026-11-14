<?php

declare(strict_types=1);

namespace Greenlight\Cli\Run;

use Greenlight\Artifact\AttachmentError;
use Greenlight\Cli\Configuration\ConfigurationLoader;
use Greenlight\Cli\Input\CliError;
use Greenlight\Cli\Input\ParsedArguments;
use Greenlight\Cli\Output\Console;
use Greenlight\Config\ConfigFileError;
use Greenlight\Config\InvalidConfiguration;
use Greenlight\Execution\ArtifactMaintenance;
use Greenlight\Plugin\CommandResult;

/**
 * Applies configured retention to completed Greenlight artifact runs and,
 * when the user selects it, removes abandoned incomplete runs.
 *
 * @internal
 */
final readonly class ArtifactsPruneCommand
{
    public function __construct(private Console $console) {}

    public function run(ParsedArguments $arguments, string $workingDirectory): CommandResult
    {
        $abandonedRunAge = null;
        $rawAbandonedRunAge = $arguments->value('abandoned-run-age');
        if ($rawAbandonedRunAge !== null) {
            $abandonedRunAge = \preg_match('/^[1-9][0-9]{0,17}$/D', $rawAbandonedRunAge) === 1
                ? \filter_var($rawAbandonedRunAge, \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
                : false;
            if ($abandonedRunAge === false) {
                $this->console->error('Set --abandoned-run-age to a positive number of seconds.', $arguments->has('no-ansi'));
                return CommandResult::usage();
            }
        }

        try {
            $configuration = new ConfigurationLoader()->load($arguments, $workingDirectory)->resolved->execution->artifacts;
            $maintenance = ArtifactMaintenance::forConfiguration($configuration, $workingDirectory);
        } catch (CliError $error) {
            $this->console->error($error->getMessage(), $arguments->has('no-ansi'));
            return CommandResult::usage();
        } catch (AttachmentError|ConfigFileError|InvalidConfiguration $error) {
            $this->console->error($error->getMessage(), $arguments->has('no-ansi'));
            return CommandResult::failure();
        }

        if (!$configuration->hasRetentionPolicy() && $abandonedRunAge === null) {
            $this->console->out("No artifact retention policy is configured.\n");
            return CommandResult::success();
        }

        $report = $maintenance->prune($arguments->has('dry-run'), $abandonedRunAge);
        foreach ($report->items as $item) {
            $action = $report->dryRun ? 'Would prune' : 'Pruned';
            $this->console->out($item->reasons === ['abandoned']
                ? \sprintf("%s %s (%d bytes, abandoned run).\n", $action, $item->directory, $item->bytes)
                : \sprintf(
                    "%s %s (%d bytes, %s limit).\n",
                    $action,
                    $item->directory,
                    $item->bytes,
                    \implode(', ', $item->reasons),
                ));
        }
        if ($report->items === []) {
            $this->console->out($report->dryRun
                ? "No completed artifact runs would be pruned.\n"
                : "No completed artifact runs were pruned.\n");
        }
        foreach ($report->warnings as $warning) {
            $this->console->err($warning . "\n");
        }

        return CommandResult::success();
    }
}
