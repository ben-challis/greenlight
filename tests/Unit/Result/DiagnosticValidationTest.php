<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Result;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Result\Diagnostic;
use Greenlight\Result\DiagnosticSeverity;

final class DiagnosticValidationTest
{
    #[Test]
    #[DataSet('nonPositiveLines')]
    public function directConstructionRejectsNonPositiveLines(int $line): void
    {
        Expect::that(
            static fn(): Diagnostic => new Diagnostic(DiagnosticSeverity::Warning, 'Warning.', '/tests/ProbeTest.php', $line),
        )
            ->because('a diagnostic MUST identify a positive source line')
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'Use a diagnostic line number greater than zero.',
            );
    }

    #[Test]
    #[DataSet('nonPositiveLines')]
    public function wireInputNormalizesNonPositiveLines(int $line): void
    {
        $restored = Diagnostic::fromWire([
            'severity' => DiagnosticSeverity::Warning->value,
            'message' => 'Warning.',
            'file' => '/tests/ProbeTest.php',
            'line' => $line,
        ]);

        Expect::that($restored->line)
            ->because('wire diagnostics MUST identify at least the first source line')
            ->toBe(1);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function nonPositiveLines(): iterable
    {
        yield 'zero' => [0];

        yield 'negative' => [-10];
    }
}
