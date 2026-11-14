<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\RequiresResource;
use Greenlight\Attribute\Test;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\PhpStanProbe;

use function Greenlight\expect;

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

        expect($probe->exitCode)
            ->because('PHPStan rejects types that cannot have a proxy')
            ->toBe(1);
        expect($probe->goodPassed)
            ->because('PHPStan messages: ' . $probe->messages())
            ->toBeTrue();
        expect(\count($probe->errors))->toBe(4);
        expect($probe->messages())
            ->toContain('Doubles::mock() cannot double FinalService because it is final')
            ->toContain('Doubles::stub() cannot double ReadonlyService because it is a readonly class')
            ->toContain('Doubles::spy() cannot double ServiceState because it is an enum')
            ->toContain('Doubles::mock() cannot double ServiceBehavior because it is a trait');
    }

    #[Test]
    public function factoriesRejectInterfacesThatUserClassesCannotImplement(): void
    {
        $probe = PhpStanProbe::analyze(
            $this->tempDirectory,
            <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Doubles\Doubles;

            /** @extends \IteratorAggregate<int, string> */
            interface AggregatePort extends \IteratorAggregate {}

            function greenlightImplementableInterfaceProbe(Doubles $doubles): void
            {
                $doubles->mock(AggregatePort::class);
                $doubles->stub(\Countable::class);
            }
            PHP,
            <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Doubles\Doubles;

            interface FailurePort extends \Throwable {}

            interface MomentPort extends \DateTimeInterface {}

            interface StatePort extends \BackedEnum {}

            /** @extends \Traversable<int, string> */
            interface SequencePort extends \Traversable {}

            function greenlightUnimplementableInterfaceProbe(Doubles $doubles): void
            {
                $doubles->mock(FailurePort::class);
                $doubles->stub(MomentPort::class);
                $doubles->spy(StatePort::class);
                $doubles->mock(SequencePort::class);
                $doubles->stub(\Throwable::class);
            }
            PHP,
        );

        expect($probe->exitCode)
            ->because('PHPStan rejects interfaces that a proxy class cannot implement')
            ->toBe(1);
        expect($probe->goodPassed)
            ->because('PHPStan messages: ' . $probe->messages())
            ->toBeTrue();
        expect(\count($probe->errors))
            ->because('PHPStan messages: ' . $probe->messages())
            ->toBe(5);
        expect($probe->messages())
            ->toContain('Doubles::mock() cannot double FailurePort because only PHP internal classes can implement Throwable')
            ->toContain('Doubles::stub() cannot double MomentPort because only PHP internal classes can implement DateTimeInterface')
            ->toContain('Doubles::spy() cannot double StatePort because only PHP internal classes can implement UnitEnum')
            ->toContain('Doubles::mock() cannot double SequencePort because it extends Traversable, but not Iterator or IteratorAggregate')
            ->toContain('Doubles::stub() cannot double Throwable because only PHP internal classes can implement Throwable');
    }
}
