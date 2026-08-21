<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\RequiresResource;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\RectorProbe;

#[RequiresResource('analysis-process')]
final readonly class RectorSurplusTestWithArgumentTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function leavesSurplusTestWithArgumentsUntouched(): void
    {
        $source = <<<'PHP_WRAP'
            <?php

            declare(strict_types=1);

            namespace App\Tests;

            use PHPUnit\Framework\Attributes\TestWith;
            use PHPUnit\Framework\TestCase;

            final class ProbeTest extends TestCase
            {
                #[TestWith([1], 'one', 'extra')]
                public function testValue(int $value): void
                {
                    $this->assertSame(1, $value);
                }
            }

            PHP_WRAP;

        $probe = RectorProbe::convert(
            $this->tempDirectory,
            $source,
            name: 'surplus-test-with-argument',
        );

        Expect::that($probe->changed)
            ->because('a TestWith attribute with surplus arguments MUST remain unsupported')
            ->toBeFalse();
        Expect::that($probe->code)
            ->because('an unsupported class MUST remain byte-identical')
            ->toBe($source);
    }
}
