<?php

declare(strict_types=1);

namespace Greenlight\Cli\Coverage;

use Greenlight\Cli\Configuration\ConfigurationLoader;
use Greenlight\Cli\Output\Console;
use Greenlight\Config\CoverageConfiguration;
use Greenlight\Coverage\CoverageMap;
use Greenlight\Coverage\Export\CloverExporter;
use Greenlight\Coverage\Export\CoberturaExporter;
use Greenlight\Coverage\Export\CoverageExporter;
use Greenlight\Coverage\Export\HtmlExporter;
use Greenlight\Coverage\Export\JsonExporter;
use Greenlight\Coverage\Export\LcovExporter;
use Greenlight\Internal\Filesystem\AtomicFile;
use Greenlight\Internal\Filesystem\AtomicFileError;
use Greenlight\Internal\Php\ErrorTrap;
use Greenlight\Reporting\Style;
use Greenlight\Reporting\SummaryFormat;

/**
 * Writes configured coverage exports and their status messages.
 *
 * @internal
 */
final readonly class CoverageWriter
{
    public function __construct(private Console $console) {}

    public function write(CoverageConfiguration $configuration, CoverageMap $coverage, string $workingDirectory, Style $style): bool
    {
        $this->console->out("\n" . SummaryFormat::coverage($coverage->totalPercentage(), $coverage->coveredLineTotal(), $coverage->executableLineTotal(), $style) . "\n");
        foreach ($configuration->exports as $export) {
            $exporter = $this->exporterFor($export->format, $workingDirectory);
            if (!$exporter instanceof CoverageExporter) {
                $this->console->err(\sprintf("Unknown coverage export format \"%s\".\n", $export->format));
                return false;
            }
            $files = $exporter->export($coverage);
            $target = ConfigurationLoader::absolutePath($export->target, $workingDirectory);
            if (\count($files) === 1) {
                ErrorTrap::run(static fn() => \mkdir(\dirname($target), 0o777, true));
                try {
                    AtomicFile::write($target, \reset($files));
                } catch (AtomicFileError $error) {
                    $this->console->err(\sprintf("Greenlight could not write the coverage export to \"%s\": %s\n", $target, $error->getMessage()));
                    return false;
                }
            } else {
                ErrorTrap::run(static fn() => \mkdir($target, 0o777, true));
                foreach ($files as $name => $content) {
                    try {
                        AtomicFile::write($target . '/' . $name, $content);
                    } catch (AtomicFileError $error) {
                        $this->console->err(\sprintf("Greenlight could not write the coverage export to \"%s\": %s\n", $target . '/' . $name, $error->getMessage()));
                        return false;
                    }
                }
            }
            $this->console->out(SummaryFormat::coverageExport($export->format, $export->target) . "\n");
        }
        return true;
    }

    private function exporterFor(string $format, string $workingDirectory): ?CoverageExporter
    {
        return match ($format) {
            'lcov' => new LcovExporter(), 'clover' => new CloverExporter(),
            'cobertura' => new CoberturaExporter(), 'html' => new HtmlExporter($workingDirectory),
            'json' => new JsonExporter(), default => null,
        };
    }
}
