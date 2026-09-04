<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Doubles;

use Greenlight\Attribute\Test;
use Greenlight\Doubles\Argument;
use Greenlight\Doubles\Doubles;
use Greenlight\Doubles\MockPlan;
use Greenlight\Expect\Expect;

final readonly class ArgumentTypeIdentityTest
{
    public function __construct(private Doubles $doubles) {}

    #[Test]
    public function argumentPlansAcceptDifferentClassNameCase(): void
    {
        $this->verifyTypeName(\strtolower(ArgumentTypeIdentityValue::class));
    }

    #[Test]
    public function argumentPlansAcceptClassAliases(): void
    {
        $alias = __NAMESPACE__ . '\\ArgumentTypeIdentityAlias';

        if (!\class_exists($alias, false)) {
            \class_alias(ArgumentTypeIdentityValue::class, $alias);
        }

        $this->verifyTypeName($alias);
    }

    private function verifyTypeName(string $type): void
    {
        $double = $this->doubles->mock(
            ArgumentTypeIdentityConsumer::class,
            static function (MockPlan $plan) use ($type): void {
                $plan->expects('receive')->with(Argument::type($type))->once();
            },
        );
        $value = new ArgumentTypeIdentityValue();
        $double->receive($value);

        Expect::that($this->doubles->callsTo($double, 'receive'))->toBe([[$value]]);
    }
}

final class ArgumentTypeIdentityValue {}

interface ArgumentTypeIdentityConsumer
{
    public function receive(ArgumentTypeIdentityValue $value): void;
}
