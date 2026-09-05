<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\RequiresResource;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\PhpStanProbe;

#[RequiresResource('analysis-process')]
final readonly class PhpStanUnpackedCaptureArgumentTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function unpackedPositionsDoNotUseTheDefaultParameterType(): void
    {
        $probe = PhpStanProbe::analyze(
            $this->tempDirectory,
            <<<'PHP'
            <?php

            use Greenlight\Doubles\Doubles;
            use Greenlight\Doubles\MockPlan;

            interface SafeCaptureTarget
            {
                public function record(int $id, string $text): void;
            }

            function acceptsCapturedMixed(mixed $value): void {}
            function acceptsDefaultInt(int $value): void {}

            function safeCapture(Doubles $doubles): void
            {
                $doubles->mock(SafeCaptureTarget::class, static function (MockPlan $plan): void {
                    acceptsCapturedMixed($plan->expects('record')->captureArgument(...[1])->value());
                    acceptsDefaultInt($plan->expects('record')->captureArgument()->value());
                });
            }
            PHP,
            <<<'PHP'
            <?php

            use Greenlight\Doubles\Doubles;
            use Greenlight\Doubles\MockPlan;

            interface UnsafeCaptureTarget
            {
                public function record(int $id, string $text): void;
            }

            function requiresCapturedInt(int $value): void {}

            /** @param array{int} $position */
            function unsafeCapture(Doubles $doubles, array $position): void
            {
                $doubles->mock(UnsafeCaptureTarget::class, static function (MockPlan $plan) use ($position): void {
                    requiresCapturedInt($plan->expects('record')->captureArgument(...[1])->value());
                    requiresCapturedInt($plan->expects('record')->captureArgument(...['position' => 1])->value());
                    requiresCapturedInt($plan->expects('record')->captureArgument(...$position)->value());
                });
            }
            PHP,
        );

        Expect::that($probe->goodPassed)->because('PHPStan messages: ' . \implode("\n", $probe->goodErrors))->toBeTrue();
        Expect::that($probe->exitCode)->toBe(1);
        Expect::that(\count($probe->errors))->toBe(3);
        Expect::that($probe->messages())->toContain('expects int, mixed given');
    }
}
