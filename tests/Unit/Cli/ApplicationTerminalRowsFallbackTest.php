<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli;

use Greenlight\Attribute\Test;
use Greenlight\Cli\TerminalRowsResolver;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Fixture\EnvironmentSandbox;
use Greenlight\Fixture\TempDirectory;

final readonly class ApplicationTerminalRowsFallbackTest
{
    public function __construct(
        private EnvironmentSandbox $environment,
        private TempDirectory $temporaryDirectory,
    ) {}

    #[Test]
    public function terminalProbeSetsRowsWhenLinesIsUnavailable(): void
    {
        $this->environment->unset('LINES');
        $this->environment->set('PATH', $this->temporaryDirectory->path());
        $this->installTput("31\n");

        $rows = TerminalRowsResolver::resolve();

        Expect::that($rows)
            ->because('the terminal probe MUST set the reporter height when LINES is unavailable')
            ->toBe(31);
    }

    #[Test]
    public function defaultRowsApplyWhenLinesAndProbeAreUnavailable(): void
    {
        $this->environment->unset('LINES');
        $this->environment->set('PATH', $this->temporaryDirectory->path());

        $rows = TerminalRowsResolver::resolve();

        Expect::that($rows)
            ->because('the reporter MUST use 24 rows when terminal height detection fails')
            ->toBe(24);
    }

    private function installTput(string $output): void
    {
        $path = $this->temporaryDirectory->path() . '/tput';
        $written = \file_put_contents($path, "#!/bin/sh\nprintf '$output'\n");

        if ($written === false || !\chmod($path, 0o700)) {
            Fail::because('The test could not install its terminal probe.');
        }
    }
}
