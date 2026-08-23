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
    public function __construct(private Console $console, private bool $humanOutputOnStderr = false) {}

    public function write(CoverageConfiguration $configuration, ?CoverageMap $coverage, string $workingDirectory, Style $style): bool
    {
        if (!$coverage instanceof CoverageMap) {
            if ($configuration->requiresCoverageResult()) {
                $this->console->err("Coverage is required, but no worker collected it. Install pcov or enable Xdebug with coverage mode.\n");

                return false;
            }

            $this->console->err("No worker collected the requested coverage. Install pcov or enable Xdebug with coverage mode.\n");

            return true;
        }

        $this->human("\n" . SummaryFormat::coverage($coverage->totalPercentage(), $coverage->coveredLineTotal(), $coverage->executableLineTotal(), $style) . "\n");
        foreach ($configuration->exports as $export) {
            $exporter = $this->exporterFor($export->format, $workingDirectory);
            if (!$exporter instanceof CoverageExporter) {
                $this->console->err(\sprintf("Unknown coverage export format \"%s\".\n", $export->format));
                return false;
            }
            try {
                $files = $exporter->export($coverage);
            } catch (\Throwable $error) {
                $this->console->err(\sprintf(
                    "Greenlight could not create the \"%s\" coverage export: %s\n",
                    $export->format,
                    $error->getMessage(),
                ));

                return false;
            }
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
            $this->human(SummaryFormat::coverageExport($export->format, $export->target) . "\n");
        }

        $failures = CoverageGate::failures($configuration, $coverage);

        foreach ($failures as $failure) {
            $this->console->err($failure . "\n");
        }

        return $failures === [];
    }

    private function human(string $text): void
    {
        if ($this->humanOutputOnStderr) {
            $this->console->err($text);

            return;
        }

        $this->console->out($text);
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
