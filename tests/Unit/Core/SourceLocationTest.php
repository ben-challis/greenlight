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
            ->toBe('/project/tests/PaymentTest.php:42');
        Expect::that($restored->file)
            ->because('the file MUST survive the wire')
            ->toBe('/project/tests/PaymentTest.php');
        Expect::that($restored->line)
            ->because('the line MUST survive the wire')
            ->toBe(42);
    }

    #[Test]
    public function preservesAZeroStringFileAcrossRenderingAndTheWire(): void
    {
        $location = new SourceLocation('0', 1);
        $restored = SourceLocation::fromWire(JsonWire::roundTrip($location->toWire()));

        Expect::that((string) $location)
            ->because('a zero-string source file is not empty')
            ->toBe('0:1');
        Expect::that($restored->file)
            ->because('the zero-string source file MUST survive the wire')
            ->toBe('0');
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

    #[Test]
    #[DataSet('invalidLocations')]
    public function rejectsInvalidConstruction(string $file, int $line, string $message): void
    {
        Expect::that(static fn(): SourceLocation => new SourceLocation($file, $line))
            ->because('source locations MUST identify a file and a positive line')
            ->toThrow(\InvalidArgumentException::class, message: $message);
    }

    /**
     * @return iterable<string, array{string, int, non-empty-string}>
     */
    public static function invalidLocations(): iterable
    {
        yield 'empty file' => ['', 1, 'Source location file must not be empty.'];
        yield 'zero line' => ['/project/tests/PaymentTest.php', 0, 'Source location line must be at least 1.'];
        yield 'negative line' => ['/project/tests/PaymentTest.php', -10, 'Source location line must be at least 1.'];
    }
}
