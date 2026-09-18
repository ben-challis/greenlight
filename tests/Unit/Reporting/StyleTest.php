<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Reporting;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Reporting\Style;

final class StyleTest
{
    #[Test]
    public function colorsApplyOnlyWithAnsi(): void
    {
        $ansi = new Style(ansi: true);
        $plain = new Style(ansi: false);

        Expect::value($ansi->ok('fine'))->because('colors apply only with ANSI')->toBe("\x1b[32mfine\x1b[0m");
        Expect::value($ansi->error('bad'))->toBe("\x1b[31mbad\x1b[0m");
        Expect::value($ansi->warn('uh oh'))->toBe("\x1b[33muh oh\x1b[0m");
        Expect::value($plain->ok('fine'))->toBe('fine');
        Expect::value($plain->error('bad'))->toBe('bad');
        Expect::value($plain->warn('uh oh'))->toBe('uh oh');
    }

    #[Test]
    public function durationsColorBySeverity(): void
    {
        $ansi = new Style(ansi: true);

        Expect::value($ansi->duration(0.123))->because('durations color by severity')->toBe('0.123s');
        Expect::value($ansi->duration(1.5))->toBe("\x1b[33m1.500s\x1b[0m");
        Expect::value($ansi->duration(6.0))->toBe("\x1b[31m6.000s\x1b[0m");
    }

    #[Test]
    public function durationColorThresholdsAreInclusive(): void
    {
        $ansi = new Style(ansi: true);

        Expect::value($ansi->duration(0.999))
            ->because('durations below one second remain uncolored')
            ->toBe('0.999s');
        Expect::value($ansi->duration(1.0))
            ->because('one second enters the warning band')
            ->toBe("\x1b[33m1.000s\x1b[0m");
        Expect::value($ansi->duration(4.999))
            ->toBe("\x1b[33m4.999s\x1b[0m");
        Expect::value($ansi->duration(5.0))
            ->because('five seconds enters the error band')
            ->toBe("\x1b[31m5.000s\x1b[0m");
    }

    #[Test]
    public function durationsStayPlainWithoutAnsi(): void
    {
        $plain = new Style(ansi: false);

        Expect::value($plain->duration(1.5))->because('durations stay plain without ANSI')->toBe('1.500s');
        Expect::value($plain->duration(6.0))->toBe('6.000s');
    }
}
