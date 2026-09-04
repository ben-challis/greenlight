<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Result;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Result\CapturedOutput;
use Greenlight\Result\Diagnostic;
use Greenlight\Result\DiagnosticSeverity;

final class CapturedOutputValidationTest
{
    /**
     * @param array<mixed> $diagnostics
     */
    #[Test]
    #[DataSet('invalidDiagnostics')]
    public function invalidDiagnosticsAreRejected(array $diagnostics): void
    {
        Expect::that(static fn(): CapturedOutput => new CapturedOutput('', $diagnostics))
            ->because('captured output diagnostics MUST be a list of Diagnostic instances')
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'Use a list of Diagnostic instances for captured output diagnostics.',
            );
    }

    /**
     * @return iterable<string, array{array<mixed>}>
     */
    public static function invalidDiagnostics(): iterable
    {
        yield 'associative diagnostics' => [[
            'warning' => new Diagnostic(DiagnosticSeverity::Warning, 'warning', 'Test.php', 1),
        ]];
        yield 'wrong item type' => [['warning']];
    }
}
