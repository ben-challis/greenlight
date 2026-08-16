<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\RequiresResource;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Tests\Support\RectorProbe;

#[RequiresResource('analysis-process')]
final readonly class RectorExceptionMessageDelimiterTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function exactMessagesEscapeTheGeneratedPatternDelimiter(): void
    {
        $probe = RectorProbe::convert(
            $this->tempDirectory,
            <<<'PHP_WRAP'
            <?php

            declare(strict_types=1);

            namespace App\Tests;

            use PHPUnit\Framework\TestCase;

            final class ProbeTest extends TestCase
            {
                public function testFails(): void
                {
                    $this->expectException(\RuntimeException::class);
                    $this->expectExceptionMessage('path /tmp');
                    throw new \RuntimeException('path /tmp');
                }
            }

            PHP_WRAP,
            name: 'exception-message-delimiter',
        );

        Expect::that($probe->changed)
            ->because('the exception expectation MUST be convertible')
            ->toBeTrue();
        Expect::that($probe->code)
            ->because('the generated matcher MUST escape its slash delimiter')
            ->toContain(
                "->toThrow(\\RuntimeException::class, matching: '/path \\/tmp/');",
            );
    }
}
