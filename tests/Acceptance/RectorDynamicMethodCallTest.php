<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\RequiresResource;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Tests\Support\RectorProbe;

#[RequiresResource('analysis-process')]
final readonly class RectorDynamicMethodCallTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function leavesDynamicMethodCallsUntouched(): void
    {
        $source = <<<'PHP_WRAP'
            <?php

            declare(strict_types=1);

            namespace App\Tests;

            use PHPUnit\Framework\TestCase;

            final class ProbeTest extends TestCase
            {
                public function testValue(): void
                {
                    $method = 'checkValue';
                    $this->{$method}(1);
                }

                private function checkValue(int $value): void
                {
                    $this->assertSame(1, $value);
                }
            }

            PHP_WRAP;

        $probe = RectorProbe::convert(
            $this->tempDirectory,
            $source,
            name: 'dynamic-method-call',
        );

        Expect::that($probe->changed)
            ->because('a dynamic method call MUST remain unsupported')
            ->toBeFalse()
            ->and($probe->code)
            ->because('an unsupported class MUST remain byte-identical')
            ->toBe($source);
    }
}
