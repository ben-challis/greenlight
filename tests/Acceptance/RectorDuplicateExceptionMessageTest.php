<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\RequiresResource;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Tests\Support\RectorProbe;

#[RequiresResource('analysis-process')]
final readonly class RectorDuplicateExceptionMessageTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function leavesDuplicateExceptionMessageConstraintsUntouched(): void
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
                    $this->expectException(\RuntimeException::class);
                    $this->expectExceptionMessage('first');
                    $this->expectExceptionMessageMatches('/second/');
                    throw new \RuntimeException('second');
                }
            }

            PHP_WRAP;

        $probe = RectorProbe::convert(
            $this->tempDirectory,
            $source,
            name: 'duplicate-exception-message',
        );

        Expect::that($probe->changed)
            ->because('duplicate exception message constraints MUST remain unsupported')
            ->toBeFalse()
            ->and($probe->code)
            ->because('an unsupported class MUST remain byte-identical')
            ->toBe($source);
    }
}
