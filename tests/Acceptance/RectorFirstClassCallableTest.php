<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\RequiresResource;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\RectorProbe;

#[RequiresResource('analysis-process')]
final readonly class RectorFirstClassCallableTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function leavesFirstClassCallableReferencesUntouched(): void
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
                    $failure = $this->fail(...);
                }
            }

            PHP_WRAP;

        $probe = RectorProbe::convert(
            $this->tempDirectory,
            $source,
            name: 'first-class-callable',
        );

        Expect::that($probe->changed)
            ->because('a first-class callable reference MUST remain unsupported')
            ->toBeFalse();
        Expect::that($probe->code)
            ->because('an unsupported class MUST remain byte-identical')
            ->toBe($source);
    }
}
