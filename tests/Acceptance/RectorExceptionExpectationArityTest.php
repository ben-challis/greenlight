<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\RequiresResource;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Tests\Support\RectorProbe;

#[RequiresResource('analysis-process')]
final readonly class RectorExceptionExpectationArityTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function leavesExceptionExpectationsWithSurplusArgumentsUntouched(): void
    {
        $source = <<<'PHP_WRAP'
            <?php

            declare(strict_types=1);

            namespace App\Tests;

            use PHPUnit\Framework\TestCase;

            final class ProbeTest extends TestCase
            {
                public function testFailure(): void
                {
                    $this->expectException(\RuntimeException::class, 'surplus');
                    throw new \RuntimeException('failure');
                }
            }

            PHP_WRAP;

        $probe = RectorProbe::convert(
            $this->tempDirectory,
            $source,
            name: 'exception-expectation-arity',
        );

        Expect::that($probe->changed)
            ->because('exception expectations with surplus arguments MUST remain unsupported')
            ->toBeFalse()
            ->and($probe->code)
            ->because('an unsupported class MUST remain byte-identical')
            ->toBe($source);
    }
}
