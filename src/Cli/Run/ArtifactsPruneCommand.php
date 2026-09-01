<?php

declare(strict_types=1);

namespace Greenlight\Cli\Run;

use Greenlight\Artifact\AttachmentError;
use Greenlight\Cli\Configuration\ConfigurationLoader;
use Greenlight\Cli\Input\CliError;
use Greenlight\Cli\Input\ParsedArguments;
use Greenlight\Cli\Output\Console;
use Greenlight\Command\ExitCode;
use Greenlight\Config\ConfigFileError;
use Greenlight\Config\InvalidConfiguration;
use Greenlight\Execution\ArtifactMaintenance;

/**
 * Applies configured retention to completed Greenlight artifact runs.
 *
 * @internal
 */
final readonly class ArtifactsPruneCommand
{
    public function __construct(private Console $console) {}

    public function run(ParsedArguments $arguments, string $workingDirectory): ExitCode
    {
        try {
            $configuration = new ConfigurationLoader()->load($arguments, $workingDirectory)->resolved->execution->artifacts;
            $maintenance = ArtifactMaintenance::forConfiguration($configuration, $workingDirectory);
        } catch (CliError $error) {
            $this->console->error($error->getMessage(), $arguments->has('no-ansi'));
            return ExitCode::usage();
        } catch (AttachmentError|ConfigFileError|InvalidConfiguration $error) {
            $this->console->error($error->getMessage(), $arguments->has('no-ansi'));
            return ExitCode::failure();
        }

        if (!$configuration->hasRetentionPolicy()) {
            $this->console->out("No artifact retention policy is configured.\n");
            return ExitCode::success();
        }

        $report = $maintenance->prune($arguments->has('dry-run'));
        foreach ($report->items as $item) {
            $action = $report->dryRun ? 'Would prune' : 'Pruned';
            $this->console->out(\sprintf(
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

        return ExitCode::success();
    }
}
