<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\RequiresResource;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Tests\Support\PhpStanProbe;

#[RequiresResource('analysis-process')]
final readonly class PhpStanCaptureArgumentReturnTypeExtensionTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function captureArgumentUsesTheSelectedParameterType(): void
    {
        $probe = PhpStanProbe::analyze(
            $this->tempDirectory,
            <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Doubles\Doubles;
            use Greenlight\Doubles\MockPlan;

            interface TypedCapturePlan
            {
                public function record(int $id, string $message): void;

                public function collect(string $name, int ...$values): void;
            }

            function acceptCapturedString(string $value): void {}

            /** @param list<string> $values */
            function acceptCapturedStrings(array $values): void {}

            function acceptCapturedInt(int $value): void {}

            function acceptCapturedMixed(mixed $value): void {}

            function greenlightTypedCaptureProbe(Doubles $doubles, int $dynamicPosition): void
            {
                $doubles->mock(TypedCapturePlan::class, static function (MockPlan $plan) use ($dynamicPosition): void {
                    $message = $plan->expects('record')->captureArgument(position: 1);
                    acceptCapturedString($message->value());
                    acceptCapturedStrings($message->values());

                    $value = $plan->expects('collect')->captureArgument(99);
                    acceptCapturedInt($value->value());

                    $dynamic = $plan->expects('record')->captureArgument($dynamicPosition);
                    acceptCapturedMixed($dynamic->value());
                });
            }
            PHP,
            <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Doubles\Doubles;
            use Greenlight\Doubles\MockPlan;

            interface MistypedCapturePlan
            {
                public function record(int $id, string $message): void;
            }

            function acceptMistypedCapture(int $value): void {}

            function greenlightMistypedCaptureProbe(Doubles $doubles): void
            {
                $doubles->mock(MistypedCapturePlan::class, static function (MockPlan $plan): void {
                    $message = $plan->expects('record')->captureArgument(1);
                    acceptMistypedCapture($message->value());
                });
            }
            PHP,
        );

        Expect::that($probe->exitCode)
            ->because('PHPStan rejects a captor value used as the wrong parameter type')
            ->toBe(1);
        Expect::that($probe->goodPassed)
            ->because('PHPStan messages: ' . $probe->messages())
            ->toBeTrue();
        Expect::that(\count($probe->errors))->toBe(1);
        Expect::that($probe->messages())->toContain('expects int, string given');
    }
}
