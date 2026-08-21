<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\RequiresResource;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\RectorProbe;

#[RequiresResource('analysis-process')]
final readonly class RectorDynamicExceptionMessageTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function dynamicExactMessagesLeaveTheClassUntouched(): void
    {
        $source = <<<'PHP_WRAP'
            <?php

            declare(strict_types=1);

            namespace App\Tests;

            use PHPUnit\Framework\TestCase;

            final class ProbeTest extends TestCase
            {
                public function testFails(): void
                {
                    $message = 'boom';
                    $this->expectException(\RuntimeException::class);
                    $this->expectExceptionMessage($message);
                    throw new \RuntimeException('boom');
                }
            }

            PHP_WRAP;
        $probe = RectorProbe::convert(
            $this->tempDirectory,
            $source,
            name: 'dynamic-exception-message',
        );

        Expect::that($probe->changed)
            ->because('a dynamic exact exception message MUST remain unsupported')
            ->toBeFalse();
        Expect::that($probe->code)
            ->because('an unsupported class MUST remain byte-identical')
            ->toBe($source);
    }
}
