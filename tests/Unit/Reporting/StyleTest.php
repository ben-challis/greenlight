<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Reporting;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Reporting\Style;

final class StyleTest
{
    #[Test]
    public function countPluralisesRegularNouns(): void
    {
        Expect::that(Style::count(1, 'test'))->toBe('1 test')
            ->and(Style::count(2, 'test'))->toBe('2 tests')
            ->and(Style::count(0, 'expectation'))->toBe('0 expectations')
            ->and(Style::count(1, 'expectation'))->toBe('1 expectation')
            ->and(Style::count(11, 'worker'))->toBe('11 workers');
    }

    #[Test]
    public function coloursApplyOnlyWithAnsi(): void
    {
        $ansi = new Style(ansi: true);
        $plain = new Style(ansi: false);

        Expect::that($ansi->pass('ok'))->toBe("\x1b[32mok\x1b[0m")
            ->and($ansi->fail('bad'))->toBe("\x1b[31mbad\x1b[0m")
            ->and($ansi->skip('meh'))->toBe("\x1b[33mmeh\x1b[0m")
            ->and($ansi->warn('uh oh'))->toBe("\x1b[33muh oh\x1b[0m")
            ->and($plain->pass('ok'))->toBe('ok')
            ->and($plain->fail('bad'))->toBe('bad')
            ->and($plain->skip('meh'))->toBe('meh')
            ->and($plain->warn('uh oh'))->toBe('uh oh');
    }

    #[Test]
    public function durationsColourBySeverity(): void
    {
        $ansi = new Style(ansi: true);

        Expect::that($ansi->duration(0.123))->toBe('0.123s')
            ->and($ansi->duration(1.5))->toBe("\x1b[33m1.500s\x1b[0m")
            ->and($ansi->duration(6.0))->toBe("\x1b[31m6.000s\x1b[0m");
    }

    #[Test]
    public function durationsStayPlainWithoutAnsi(): void
    {
        $plain = new Style(ansi: false);

        Expect::that($plain->duration(1.5))->toBe('1.500s')
            ->and($plain->duration(6.0))->toBe('6.000s');
    }
}
