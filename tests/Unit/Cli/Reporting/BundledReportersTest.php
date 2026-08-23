<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli\Reporting;

use Greenlight\Attribute\Test;
use Greenlight\Cli\Input\ParsedArguments;
use Greenlight\Cli\Output\TerminalCapabilities;
use Greenlight\Cli\Reporting\BundledReporters;
use Greenlight\Expect\Expect;
use Greenlight\Reporting\GithubReporter;
use Greenlight\Reporting\JsonLinesReporter;
use Greenlight\Reporting\JUnitReporter;
use Greenlight\Reporting\PlainReporter;
use Greenlight\Reporting\RunHeader;
use Greenlight\Reporting\TeamCityReporter;
use Greenlight\Reporting\TtyReporter;
use Greenlight\Tests\Unit\Reporting\BufferOutput;

final readonly class BundledReportersTest
{
    #[Test]
    public function suppliesEachBundledReporterThroughThePluginCapability(): void
    {
        $definitions = new BundledReporters(
            new TerminalCapabilities(false, false),
            new RunHeader('1.0.0'),
            new ParsedArguments(null, []),
            24,
        )->reporters();
        $output = new BufferOutput();
        $reporters = [];

        foreach ($definitions as $definition) {
            $reporters[$definition->name] = ($definition->factory)($output)::class;
        }

        Expect::that($reporters)->toBe([
            'tty' => TtyReporter::class,
            'plain' => PlainReporter::class,
            'junit' => JUnitReporter::class,
            'jsonl' => JsonLinesReporter::class,
            'github' => GithubReporter::class,
            'teamcity' => TeamCityReporter::class,
        ]);
    }
}
