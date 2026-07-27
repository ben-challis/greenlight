<?php

declare(strict_types=1);

namespace Greenlight\Reporting;

use Greenlight\Core\AtomicFile;
use Greenlight\Core\AtomicFileError;
use Greenlight\Core\ErrorTrap;
use Greenlight\Core\Event\Event;
use Greenlight\Reporting\Output\StringOutput;

/**
 * Writes one complete JUnit XML document to a file.
 *
 * The reporter creates parent directories and replaces the file atomically.
 *
 * @internal
 */
final readonly class JUnitFileReporter implements Reporter
{
    private StringOutput $output;

    private JUnitReporter $reporter;

    public function __construct(private string $path)
    {
        $this->output = new StringOutput();
        $this->reporter = new JUnitReporter($this->output);
    }

    #[\Override]
    public function onEvent(Event $event): void
    {
        $this->reporter->onEvent($event);
    }

    #[\Override]
    public function finish(): void
    {
        $this->reporter->finish();

        $directory = \dirname($this->path);

        if (!\is_dir($directory)) {
            ErrorTrap::run(static fn(): bool => \mkdir($directory, 0o777, true));
        }

        try {
            AtomicFile::write($this->path, $this->output->contents());
        } catch (AtomicFileError $error) {
            throw ReportingError::junitFileWriteFailed($this->path, $error->getMessage());
        }
    }
}
