<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\RequiresResource;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\RectorProbe;

#[RequiresResource('analysis-process')]
final readonly class RectorUnsupportedExceptionConstraintTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function leavesUnsupportedExceptionConstraintsUntouched(): void
    {
        $cases = [
            'duplicate exception types' => <<<'PHP_WRAP'
                <?php

                declare(strict_types=1);

                namespace App\Tests;

                use PHPUnit\Framework\TestCase;

                final class ProbeTest extends TestCase
                {
                    public function testFails(): void
                    {
                        $this->expectException(\RuntimeException::class);
                        $this->expectException(\LogicException::class);
                        throw new \LogicException('boom');
                    }
                }

                PHP_WRAP,
            'duplicate exception messages' => <<<'PHP_WRAP'
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

                PHP_WRAP,
            'dynamic exception message' => <<<'PHP_WRAP'
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

                PHP_WRAP,
        ];

        $probes = RectorProbe::convertBatch($this->tempDirectory, $cases, name: 'unsupported-exception-constraints');

        foreach ($probes as $caseName => $probe) {
            Expect::that($probe->changed)->because('unsupported exception constraint: ' . $caseName)->toBeFalse();
            Expect::that($probe->code)->because('unsupported exception constraint: ' . $caseName)->toBe($cases[$caseName]);
        }
    }
}
