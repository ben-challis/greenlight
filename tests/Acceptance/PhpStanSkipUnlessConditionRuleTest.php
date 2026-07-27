<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Tests\Support\PhpStanProbe;

final readonly class PhpStanSkipUnlessConditionRuleTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function skipUnlessArgumentsMustMatchTheConditionConstructor(): void
    {
        $probe = PhpStanProbe::analyze(
            $this->tempDirectory,
            <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace GreenlightSkipUnlessConditionProbe;

            use Greenlight\Attribute\SkipUnless;
            use Greenlight\Core\Condition;

            final readonly class GoodCondition implements Condition
            {
                public function __construct(private string $name, private ?int $value = null) {}

                public function isSatisfied(): bool
                {
                    return $this->name !== '' && $this->value !== 0;
                }
            }

            #[SkipUnless(GoodCondition::class, 'APP_ENV', 1)]
            final class GoodSkipUnlessConditionProbe {}
            PHP,
            <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace GreenlightSkipUnlessConditionProbe;

            use Greenlight\Attribute\SkipUnless;
            use Greenlight\Core\Condition;

            final readonly class TwoArgumentsCondition implements Condition
            {
                public function __construct(private string $first, private string $second) {}

                public function isSatisfied(): bool
                {
                    return $this->first === $this->second;
                }
            }

            final readonly class IntegerCondition implements Condition
            {
                public function __construct(private int $value) {}

                public function isSatisfied(): bool
                {
                    return $this->value > 0;
                }
            }

            #[SkipUnless(TwoArgumentsCondition::class, 'one')]
            final class TooFewConditionArgumentsProbe {}

            #[SkipUnless(IntegerCondition::class, 'one')]
            final class WrongConditionArgumentTypeProbe {}
            PHP,
        );

        Expect::that($probe->exitCode)->because('skip-unless arguments must match the condition constructor')->toBe(1)
            ->and($probe->goodPassed)->toBeTrue()
            ->and(\count($probe->errors))->toBe(2)
            ->and($probe->messages())->toContain('#[SkipUnless] supplies 1 argument to the GreenlightSkipUnlessConditionProbe\TwoArgumentsCondition constructor, but the constructor requires 2 arguments')
            ->toContain('#[SkipUnless] argument #1 for GreenlightSkipUnlessConditionProbe\IntegerCondition constructor parameter $value has type string, but the parameter requires int');
    }
}
