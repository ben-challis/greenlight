<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\RequiresResource;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Tests\Support\RectorProbe;

#[RequiresResource('analysis-process')]
final readonly class RectorUnpackedTestWithTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function leavesUnpackedTestWithArgumentsUntouched(): void
    {
        $source = <<<'PHP_WRAP'
            <?php

            declare(strict_types=1);

            namespace App\Tests;

            use PHPUnit\Framework\Attributes\TestWith;
            use PHPUnit\Framework\TestCase;

            final class ProbeTest extends TestCase
            {
                #[TestWith(...[[1]])]
                public function testValue(int $value): void
                {
                    $this->assertSame(1, $value);
                }
            }

            PHP_WRAP;

        $probe = RectorProbe::convert(
            $this->tempDirectory,
            $source,
            name: 'unpacked-test-with',
        );

        Expect::that($probe->changed)
            ->because('a TestWith attribute with unpacked arguments MUST remain unsupported')
            ->toBeFalse();
        Expect::that($probe->code)
            ->because('an unsupported class MUST remain byte-identical')
            ->toBe($source);
    }
}
