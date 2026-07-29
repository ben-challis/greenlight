<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\RequiresResource;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Tests\Support\RectorProbe;

#[RequiresResource('analysis-process')]
final readonly class RectorUnsupportedAssertCallTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function leavesUnsupportedAssertCallsUntouched(): void
    {
        $source = <<<'PHP_WRAP'
            <?php

            declare(strict_types=1);

            namespace App\Tests;

            use PHPUnit\Framework\Assert;
            use PHPUnit\Framework\Constraint\IsTrue;
            use PHPUnit\Framework\TestCase;

            final class ProbeTest extends TestCase
            {
                public function testValue(): void
                {
                    Assert::assertThat(true, new IsTrue());
                }
            }

            PHP_WRAP;

        $probe = RectorProbe::convert(
            $this->tempDirectory,
            $source,
            name: 'unsupported-assert-call',
        );

        Expect::that($probe->changed)
            ->because('an unsupported Assert call MUST remain unsupported')
            ->toBeFalse()
            ->and($probe->code)
            ->because('an unsupported class MUST remain byte-identical')
            ->toBe($source);
    }
}
