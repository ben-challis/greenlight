<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\RequiresResource;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\RectorProbe;

#[RequiresResource('analysis-process')]
final readonly class RectorDefaultFailureMessageTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function failureWithoutAMessageGetsANonEmptyDefault(): void
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
                    $this->fail();
                }
            }

            PHP_WRAP,
            name: 'default-failure-message',
        );

        Expect::that($probe->changed)
            ->because('a PHPUnit failure without a message MUST be convertible')
            ->toBeTrue();
        Expect::that($probe->code)
            ->because('Greenlight failures MUST have a non-empty reason')
            ->toContain("\Greenlight\Expect\Fail::because('Test failed.');");
    }
}
