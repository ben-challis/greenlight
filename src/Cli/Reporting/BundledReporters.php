<?php

declare(strict_types=1);

namespace Greenlight\Cli\Reporting;

use Greenlight\Cli\Input\ParsedArguments;
use Greenlight\Cli\Output\TerminalCapabilities;
use Greenlight\Plugin\ReporterProvider;
use Greenlight\Reporting\GithubReporter;
use Greenlight\Reporting\JsonLinesReporter;
use Greenlight\Reporting\JUnitReporter;
use Greenlight\Reporting\Output;
use Greenlight\Reporting\PlainReporter;
use Greenlight\Reporting\Reporter;
use Greenlight\Reporting\ReporterDefinition;
use Greenlight\Reporting\RunHeader;
use Greenlight\Reporting\TeamCityReporter;
use Greenlight\Reporting\TtyReporter;

/**
 * Supplies the reporters that Greenlight includes.
 *
 * @internal
 */
final readonly class BundledReporters implements ReporterProvider
{
    /**
     * @param positive-int $terminalRows
     */
    public function __construct(
        private TerminalCapabilities $capabilities,
        private RunHeader $header,
        private ParsedArguments $arguments,
        private int $terminalRows,
    ) {}

    /** @return list<ReporterDefinition> */
    #[\Override]
    public function reporters(): array
    {
        $profile = $this->arguments->has('profile');

        return [
            new ReporterDefinition('tty', function (Output $output) use ($profile): Reporter {
                $capabilities = $output instanceof ReporterOutput ? $output->capabilities : $this->capabilities;

                return new TtyReporter(
                    $output,
                    $capabilities->color,
                    $capabilities->interactive,
                    $this->header,
                    extendedSlowTests: $profile,
                    verbose: $this->arguments->has('verbose'),
                    terminalRows: $this->terminalRows,
                );
            }),
            new ReporterDefinition('plain', fn(Output $output): Reporter => new PlainReporter($output, $this->header, extendedSlowTests: $profile)),
            new ReporterDefinition('junit', static fn(Output $output): Reporter => new JUnitReporter($output)),
            new ReporterDefinition('jsonl', static fn(Output $output): Reporter => new JsonLinesReporter($output)),
            new ReporterDefinition('github', static fn(Output $output): Reporter => new GithubReporter($output)),
            new ReporterDefinition('teamcity', static fn(Output $output): Reporter => new TeamCityReporter($output)),
        ];
    }
}
