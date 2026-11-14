<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Doubles;

use Greenlight\Attribute\DataRow;
use Greenlight\Attribute\Test;
use Greenlight\Doubles\Argument;
use Greenlight\Doubles\Doubles;
use Greenlight\Doubles\InvalidDoubleUsage;
use Greenlight\Doubles\MockPlan;
use Greenlight\Expect\ExpectationFailed;
use Greenlight\Tests\Fixture\Doubles\TypedArguments;

use function Greenlight\expect;

final readonly class ArgumentTypeNameTest
{
    public function __construct(private Doubles $doubles) {}

    #[Test]
    #[DataRow(['callable', 'strlen'], label: 'callable')]
    #[DataRow(['iterable', [1]], label: 'iterable')]
    #[DataRow(['object', new \stdClass()], label: 'object')]
    #[DataRow(['mixed', null], label: 'mixed')]
    #[DataRow(['true', true], label: 'true')]
    #[DataRow(['\stdClass', new \stdClass()], label: 'leading separator')]
    public function pseudoTypesMatchTheirValues(string $type, mixed $value): void
    {
        expect(Argument::type($type)->matches($value))
            ->because('a type matcher MUST accept a value of its pseudo-type')
            ->toBeTrue();
    }

    #[Test]
    public function resourceDebugNamesRemainValid(): void
    {
        $stream = \fopen('php://memory', 'rb');

        try {
            expect(Argument::type('resource (stream)')->matches($stream))
                ->because('get_debug_type() names a resource type')
                ->toBeTrue();
        } finally {
            if (\is_resource($stream)) {
                \fclose($stream);
            }
        }
    }

    #[Test]
    public function aPlanForAPseudoTypeMatchesACall(): void
    {
        $double = $this->doubles->mock(TypedArguments::class, static function (MockPlan $plan): void {
            $plan->expects('accept')->with(Argument::type('callable'))->once();
        });

        expect()->calling(static fn() => $double->accept('strlen'))
            ->because('a type matcher for a pseudo-type MUST match a value of that type')
            ->not()->toThrow(ExpectationFailed::class);
    }

    #[Test]
    #[DataRow(['type'], label: 'type')]
    #[DataRow(['union'], label: 'union')]
    #[DataRow(['intersection'], label: 'intersection')]
    public function unknownTypeNamesAreRejected(string $factory): void
    {
        $missing = 'Greenlight\Tests\Missing\Clas';
        $create = match ($factory) {
            'type' => static fn(): mixed => Argument::type($missing),
            'union' => static fn(): mixed => Argument::union('int', $missing),
            default => static fn(): mixed => Argument::intersection(\Countable::class, $missing),
        };

        expect()->calling($create)
            ->because('a matcher for an unknown type can never accept a value')
            ->toThrow(
                InvalidDoubleUsage::class,
                message: \sprintf(
                    'Argument::%s() does not know the type "Greenlight\Tests\Missing\Clas". '
                    . 'Use a built-in type, or a class, interface, or enum that PHP can load.',
                    $factory,
                ),
            );
    }

    #[Test]
    public function bareValuesMustSatisfyTheParameterType(): void
    {
        expect()->calling(fn(): object => $this->doubles->mock(TypedArguments::class, static function (MockPlan $plan): void {
            $plan->expects('scale')->with('large'); // @phpstan-ignore greenlight.mockPlan.argument (The test checks the runtime rejection.)
        }))
            ->because('a bare value that the parameter rejects can never match a call')
            ->toThrow(
                InvalidDoubleUsage::class,
                message: 'The value in with() argument 1 has type "string", but parameter "$factor" of '
                    . '"Greenlight\Tests\Fixture\Doubles\TypedArguments::scale()" requires "float". No call can match it.',
            );
    }

    #[Test]
    public function integerValuesMatchFloatParameters(): void
    {
        $double = $this->doubles->mock(TypedArguments::class, static function (MockPlan $plan): void {
            $plan->expects('scale')->with(5)->once()->andReturns(10.0);
        });

        expect($double->scale(5))
            ->because('PHP converts an integer argument for a float parameter')
            ->toBe(10.0);
    }
}
