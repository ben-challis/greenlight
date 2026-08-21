<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\RequiresResource;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\RectorProbe;

#[RequiresResource('analysis-process')]
final readonly class RectorMessageOnlyThrowableTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function messageOnlyExceptionExpectationsAcceptAllThrowables(): void
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
                public function testError(): void
                {
                    $this->expectExceptionMessage('boom');
                    throw new \Error('boom');
                }
            }

            PHP_WRAP,
            name: 'message-only-throwable',
        );

        Expect::that($probe->changed)
            ->because('the message-only exception expectation MUST be convertible')
            ->toBeTrue();
        Expect::that($probe->code)
            ->because('the converted expectation MUST accept all throwable values')
            ->toContain("->toThrow(\Throwable::class, matching: '/boom/');");
    }
}
