<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Reporting;

use Greenlight\Attribute\Test;
use Greenlight\Reporting\Style;

use function Greenlight\expect;

final class StyleTest
{
    #[Test]
    public function colorsApplyOnlyWithAnsi(): void
    {
        $ansi = new Style(ansi: true);
        $plain = new Style(ansi: false);

        expect($ansi->ok('fine'))->because('colors apply only with ANSI')->toBe("\x1b[32mfine\x1b[0m");
        expect($ansi->error('bad'))->toBe("\x1b[31mbad\x1b[0m");
        expect($ansi->warn('uh oh'))->toBe("\x1b[33muh oh\x1b[0m");
        expect($plain->ok('fine'))->toBe('fine');
        expect($plain->error('bad'))->toBe('bad');
        expect($plain->warn('uh oh'))->toBe('uh oh');
    }

    #[Test]
    public function durationsColorBySeverity(): void
    {
        $ansi = new Style(ansi: true);

        expect($ansi->duration(0.123))->because('durations color by severity')->toBe('0.123s');
        expect($ansi->duration(1.5))->toBe("\x1b[33m1.500s\x1b[0m");
        expect($ansi->duration(6.0))->toBe("\x1b[31m6.000s\x1b[0m");
    }

    #[Test]
    public function durationColorThresholdsAreInclusive(): void
    {
        $ansi = new Style(ansi: true);

        expect($ansi->duration(0.999))
            ->because('durations below one second remain uncolored')
            ->toBe('0.999s');
        expect($ansi->duration(1.0))
            ->because('one second enters the warning band')
            ->toBe("\x1b[33m1.000s\x1b[0m");
        expect($ansi->duration(4.999))
            ->toBe("\x1b[33m4.999s\x1b[0m");
        expect($ansi->duration(5.0))
            ->because('five seconds enters the error band')
            ->toBe("\x1b[31m5.000s\x1b[0m");
    }

    #[Test]
    public function durationsStayPlainWithoutAnsi(): void
    {
        $plain = new Style(ansi: false);

        expect($plain->duration(1.5))->because('durations stay plain without ANSI')->toBe('1.500s');
        expect($plain->duration(6.0))->toBe('6.000s');
    }
}
