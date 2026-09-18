<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\RequiresResource;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\PhpStanProbe;

#[RequiresResource('analysis-process')]
final readonly class PhpStanDoubleableTypeRuleTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function factoriesRequireTypesThatCanHaveAProxy(): void
    {
        $probe = PhpStanProbe::analyze(
            $this->tempDirectory,
            <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Doubles\Doubles;

            interface DoubleablePort {}

            class DoubleableService {}

            /** @param class-string<object> $dynamicType */
            function greenlightDoubleableTypeProbe(Doubles $doubles, string $dynamicType): void
            {
                $doubles->mock(DoubleablePort::class);
                $doubles->stub(type: DoubleableService::class);
                $doubles->spy($dynamicType);
            }
            PHP,
            <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Doubles\Doubles;

            final class FinalService {}

            readonly class ReadonlyService {}

            enum ServiceState
            {
                case Ready;
            }

            trait ServiceBehavior {}

            function greenlightNonDoubleableTypeProbe(Doubles $doubles): void
            {
                $doubles->mock(FinalService::class);
                $doubles->stub(type: ReadonlyService::class);
                $doubles->spy(ServiceState::class);
                $doubles->mock(ServiceBehavior::class);
            }
            PHP,
        );

        Expect::value($probe->exitCode)
            ->because('PHPStan rejects types that cannot have a proxy')
            ->toBe(1);
        Expect::value($probe->goodPassed)
            ->because('PHPStan messages: ' . $probe->messages())
            ->toBeTrue();
        Expect::value(\count($probe->errors))->toBe(4);
        Expect::value($probe->messages())->toContain('Doubles::mock() cannot double FinalService because it is final');
        Expect::value($probe->messages())->toContain('Doubles::stub() cannot double ReadonlyService because it is a readonly class');
        Expect::value($probe->messages())->toContain('Doubles::spy() cannot double ServiceState because it is an enum');
        Expect::value($probe->messages())->toContain('Doubles::mock() cannot double ServiceBehavior because it is a trait');
    }
}
