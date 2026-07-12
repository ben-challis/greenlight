<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Reporting;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Reporting\RunHeader;
use Greenlight\Reporting\Style;

final class RunHeaderTest
{
    #[Test]
    public function rendersTwoPlainLinesWhenEveryFieldIsPresent(): void
    {
        $header = new RunHeader('0.4.0', 'greenlight.php', 123456, phpVersion: '8.3.1');

        Expect::that($header->render(11, new Style(ansi: false)))
            ->toBe("Greenlight 0.4.0\nPHP 8.3.1 | config: greenlight.php | workers: 11 | seed: 123456");
    }

    #[Test]
    public function coloursTheNameGreenAndTheSeedDim(): void
    {
        $header = new RunHeader('0.4.0', 'greenlight.php', 123456, phpVersion: '8.3.1');

        Expect::that($header->render(11, new Style(ansi: true)))
            ->toBe("\x1b[32mGreenlight\x1b[0m 0.4.0\nPHP 8.3.1 | config: greenlight.php | workers: 11 | \x1b[2mseed: 123456\x1b[0m");
    }

    #[Test]
    public function omitsTheSeedWhenAbsent(): void
    {
        $header = new RunHeader('dev-main', 'greenlight.php', null, phpVersion: '8.4.0');

        Expect::that($header->render(1, new Style(ansi: false)))
            ->toBe("Greenlight dev-main\nPHP 8.4.0 | config: greenlight.php | workers: 1");
    }

    #[Test]
    public function flagsAMissingConfigFile(): void
    {
        $header = new RunHeader('dev-main', null, null, phpVersion: '8.4.0');

        Expect::that($header->render(1, new Style(ansi: false)))
            ->toBe("Greenlight dev-main\nPHP 8.4.0 | config: (none) | workers: 1")
            ->and($header->render(1, new Style(ansi: true)))
            ->toContain("\x1b[33mconfig: (none)\x1b[0m");
    }

    #[Test]
    public function flagsTheWorkerFallback(): void
    {
        $header = new RunHeader('dev-main', 'greenlight.php', null, phpVersion: '8.4.0', workerFallback: true);

        Expect::that($header->render(1, new Style(ansi: true)))
            ->toContain("\x1b[33mworkers: 1\x1b[0m")
            ->and($header->render(1, new Style(ansi: false)))
            ->toContain('workers: 1');
    }

    #[Test]
    public function phpVersionDefaultsToTheRuntime(): void
    {
        $header = new RunHeader('dev-main');

        Expect::that($header->render(2, new Style(ansi: false)))->toContain('PHP ' . \PHP_VERSION);
    }
}
