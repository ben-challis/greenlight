<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Core;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Core\Result\SourceLocation;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Support\JsonWire;

final class SourceLocationTest
{
    #[Test]
    public function rendersAndRoundTripsTheDiagnosticLocation(): void
    {
        $location = new SourceLocation('/project/tests/PaymentTest.php', 42);
        $restored = SourceLocation::fromWire(JsonWire::roundTrip($location->toWire()));

        Expect::that((string) $location)
            ->because('diagnostics MUST render a conventional file and line location')
            ->toBe('/project/tests/PaymentTest.php:42')
            ->and($restored->file)
            ->because('the file MUST survive the wire')
            ->toBe('/project/tests/PaymentTest.php')
            ->and($restored->line)
            ->because('the line MUST survive the wire')
            ->toBe(42);
    }

    #[Test]
    #[DataSet('nonPositiveLines')]
    public function wireInputClampsNonPositiveLinesToTheFirstLine(int $line): void
    {
        $restored = SourceLocation::fromWire([
            'file' => '/project/tests/PaymentTest.php',
            'line' => $line,
        ]);

        Expect::that($restored->line)
            ->because('wire locations MUST identify at least the first source line')
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
