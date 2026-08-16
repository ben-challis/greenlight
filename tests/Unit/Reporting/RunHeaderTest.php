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

        Expect::that($header->render(11, new Style(ansi: false)))->because('renders two plain lines when every field is present')
            ->toBe("Greenlight 0.4.0\nPHP 8.3.1 | configuration: greenlight.php | workers: 11 | seed: 123456");
    }

    #[Test]
    public function colorsTheNameGreenAndTheSeedDim(): void
    {
        $header = new RunHeader('0.4.0', 'greenlight.php', 123456, phpVersion: '8.3.1');

        Expect::that($header->render(11, new Style(ansi: true)))->because('colors the name green and the seed dim')
            ->toBe("\x1b[32mGreenlight\x1b[0m 0.4.0\nPHP 8.3.1 | configuration: greenlight.php | workers: 11 | \x1b[2mseed: 123456\x1b[0m");
    }

    #[Test]
    public function omitsTheSeedWhenAbsent(): void
    {
        $header = new RunHeader('dev-main', 'greenlight.php', null, phpVersion: '8.4.0');

        Expect::that($header->render(1, new Style(ansi: false)))->because('omits the seed when absent')
            ->toBe("Greenlight dev-main\nPHP 8.4.0 | configuration: greenlight.php | workers: 1");
    }

    #[Test]
    public function rendersAZeroSeed(): void
    {
        $header = new RunHeader('dev-main', 'greenlight.php', 0, phpVersion: '8.4.0');

        Expect::that($header->render(1, new Style(ansi: false)))
            ->because('the run header MUST print seed zero so the order can be reproduced')
            ->toBe("Greenlight dev-main\nPHP 8.4.0 | configuration: greenlight.php | workers: 1 | seed: 0");
    }

    #[Test]
    public function flagsAMissingConfigFile(): void
    {
        $header = new RunHeader('dev-main', null, null, phpVersion: '8.4.0');

        Expect::that($header->render(1, new Style(ansi: false)))->because('flags a missing configuration file')
            ->toBe("Greenlight dev-main\nPHP 8.4.0 | configuration: (none) | workers: 1");
        Expect::that($header->render(1, new Style(ansi: true)))
            ->toContain("\x1b[33mconfiguration: (none)\x1b[0m");
    }

    #[Test]
    public function flagsTheWorkerFallback(): void
    {
        $header = new RunHeader('dev-main', 'greenlight.php', null, phpVersion: '8.4.0', workerFallback: true);

        Expect::that($header->render(1, new Style(ansi: true)))->because('flags the worker fallback')
            ->toContain("\x1b[33mworkers: 1\x1b[0m");
        Expect::that($header->render(1, new Style(ansi: false)))
            ->toContain('workers: 1');
    }

    #[Test]
    public function phpVersionDefaultsToCurrentPhpVersion(): void
    {
        $header = new RunHeader('dev-main');

        Expect::that($header->render(2, new Style(ansi: false)))->because('PHP version defaults to the current PHP version')->toContain('PHP ' . \PHP_VERSION);
    }
}
