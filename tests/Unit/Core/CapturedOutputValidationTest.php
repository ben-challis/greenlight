<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Core;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Core\Result\CapturedOutput;
use Greenlight\Core\Result\Diagnostic;
use Greenlight\Core\Result\DiagnosticSeverity;
use Greenlight\Expect\Expect;

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
                message: 'Captured output diagnostics MUST be a list of Diagnostic instances.',
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
