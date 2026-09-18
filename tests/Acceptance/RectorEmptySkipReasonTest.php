<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\RequiresResource;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\RectorProbe;

#[RequiresResource('analysis-process')]
final readonly class RectorEmptySkipReasonTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function anEmptyLiteralReasonRemainsASkipAfterMigration(): void
    {
        $probe = RectorProbe::convert($this->tempDirectory, <<<'PHP_WRAP'
        <?php

        declare(strict_types=1);

        final class ProbeTest extends \PHPUnit\Framework\TestCase
        {
            public function testSkips(): void
            {
                $this->markTestSkipped('');
            }
        }
        PHP_WRAP);

        Expect::value($probe->changed)->toBeTrue();
        $run = $probe->runConvertedTests();

        Expect::value($run->exitCode)->because('The converted test must remain skipped.')->toBe(0);
        Expect::value($run->stdout)->toContain('1 skipped');
    }

    #[Test]
    public function aPossiblyEmptyDynamicReasonPreservesTheOriginalClass(): void
    {
        $source = <<<'PHP_WRAP'
        <?php

        declare(strict_types=1);

        final class ProbeTest extends \PHPUnit\Framework\TestCase
        {
            public function testSkips(): void
            {
                $this->markTestSkipped($this->reason());
            }

            private function reason(): string
            {
                return '';
            }
        }
        PHP_WRAP;
        $probe = RectorProbe::convert($this->tempDirectory, $source);

        Expect::value($probe->code)->toBe($source);
    }

    #[Test]
    public function aKnownNonEmptyDynamicReasonStillConverts(): void
    {
        $probe = RectorProbe::convert($this->tempDirectory, <<<'PHP_WRAP'
        <?php

        declare(strict_types=1);

        final class ProbeTest extends \PHPUnit\Framework\TestCase
        {
            public function testSkips(): void
            {
                $reason = 'Skipped dynamically.';
                $this->markTestSkipped($reason);
            }
        }
        PHP_WRAP);

        Expect::value($probe->changed)->toBeTrue();
        $run = $probe->runConvertedTests();

        Expect::value($run->exitCode)->because('The converted test must remain skipped.')->toBe(0);
        Expect::value($run->stdout)->toContain('1 skipped')->toContain('Skipped dynamically.');
    }
}
