<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Cli\TerminalRowsResolver;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Sandbox\EnvironmentVariables;
use Greenlight\Sandbox\TemporaryDirectory;

final readonly class ApplicationTerminalRowsFallbackTest
{
    public function __construct(
        private EnvironmentVariables $environment,
        private TemporaryDirectory $temporaryDirectory,
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

    #[Test]
    #[DataSet('malformedRowSources')]
    public function malformedRowSourcesUseTheDefault(string|false $lines, ?string $probeOutput): void
    {
        if ($lines === false) {
            $this->environment->unset('LINES');
        } else {
            $this->environment->set('LINES', $lines);
        }

        $this->environment->set('PATH', $this->temporaryDirectory->path());

        if ($probeOutput !== null) {
            $this->installTput($probeOutput);
        }

        Expect::that(TerminalRowsResolver::resolve())
            ->because('malformed terminal row text MUST NOT set the reporter height')
            ->toBe(24);
    }

    /**
     * @return iterable<string, array{string|false, string|null}>
     */
    public static function malformedRowSources(): iterable
    {
        yield 'LINES numeric prefix' => ['31rows', null];
        yield 'LINES integer overflow' => [\str_repeat('9', 30), null];
        yield 'terminal probe numeric prefix' => [false, "31rows\n"];
        yield 'terminal probe integer overflow' => [false, \str_repeat('9', 30) . "\n"];
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
