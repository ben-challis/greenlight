<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Result;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Result\DiagnosticSeverity;

final class DiagnosticSeverityTest
{
    #[Test]
    #[DataSet('errorLevels')]
    public function mapsPhpErrorLevelsExactly(int $level, ?DiagnosticSeverity $expected): void
    {
        Expect::that(DiagnosticSeverity::fromErrorLevel($level))
            ->because('the PHP error level has an explicit capture severity')
            ->toBe($expected);
    }

    /**
     * @return iterable<string, array{int, DiagnosticSeverity|null}>
     */
    public static function errorLevels(): iterable
    {
        yield 'engine notice' => [\E_NOTICE, DiagnosticSeverity::Notice];
        yield 'user notice' => [\E_USER_NOTICE, DiagnosticSeverity::Notice];
        yield 'engine warning' => [\E_WARNING, DiagnosticSeverity::Warning];
        yield 'user warning' => [\E_USER_WARNING, DiagnosticSeverity::Warning];
        yield 'engine deprecation' => [\E_DEPRECATED, DiagnosticSeverity::Deprecation];
        yield 'user deprecation' => [\E_USER_DEPRECATED, DiagnosticSeverity::Deprecation];
        yield 'unsupported error' => [\E_ERROR, null];
    }
}
