<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Expect;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Expect\ExpectationRuntime;
use Greenlight\Tests\Fixture\Expect\FakeClock;

use function Greenlight\expect;

final class BecauseChainTest
{
    /** @param \Closure(): mixed $assertion */
    #[Test]
    #[DataSet('chains')]
    public function theReasonSurvivesMatcherAndModeTransitions(\Closure $assertion): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => ExpectationRuntime::withClock(new FakeClock(), $assertion),
        );

        expect($detail->message)->toContain('because the result must be valid.');
    }

    /** @return iterable<string, array{\Closure(): mixed}> */
    public static function chains(): iterable
    {
        yield 'call return' => [static fn() => expect()->calling(static fn(): int => 1)
            ->because('the result must be valid')->toReturn(1)->toReturn(2)];
        yield 'call throw' => [static fn() => expect()->calling(static fn() => throw new \RuntimeException('ready'))
            ->because('the result must be valid')->toThrow(\RuntimeException::class)->toThrow(\LogicException::class)];
        yield 'return value' => [static fn() => expect()->calling(static fn(): int => 1)
            ->because('the result must be valid')->toReturn(1)->returnValue()->toBeInt()->toBe(2)];
        yield 'negated call' => [static fn() => expect()->calling(static fn(): int => 1)
            ->because('the result must be valid')->not()->toThrow()->toReturn(1)->toReturn(2)];
        yield 'eventual value' => [static fn() => expect()->calling(static fn(): int => 1)
            ->because('the result must be valid')->toReturn(1)->returnValue()
            ->eventually()->within(0.01)->toBe(1)->toBe(2)];
        yield 'consistent value' => [static fn() => expect()->calling(static fn(): int => 1)
            ->because('the result must be valid')->toReturn(1)->returnValue()
            ->consistently()->for(0.01)->toBe(1)->toBe(2)];
        yield 'eventual return' => [static fn() => expect()->calling(static fn(): int => 1)
            ->because('the result must be valid')->toReturn(1)
            ->eventually()->within(0.01)->toReturn(1)->toReturn(2)];
        yield 'consistent return' => [static fn() => expect()->calling(static fn(): int => 1)
            ->because('the result must be valid')->toReturn(1)
            ->consistently()->for(0.01)->toReturn(1)->toReturn(2)];
        yield 'eventual throw' => [static fn() => expect()->calling(static fn() => throw new \RuntimeException('ready'))
            ->because('the result must be valid')->toThrow()
            ->eventually()->within(0.01)->toThrow()->toThrow(\LogicException::class)];
        yield 'consistent throw' => [static fn() => expect()->calling(static fn() => throw new \RuntimeException('ready'))
            ->because('the result must be valid')->toThrow()
            ->consistently()->for(0.01)->toThrow()->toThrow(\LogicException::class)];
        yield 'pending value reason' => [static fn() => expect()->calling(static fn(): int => 1)
            ->returnValue()->eventually()->because('the result must be valid')
            ->within(0.01)->toBe(1)->toBe(2)];
        yield 'pending call reason' => [static fn() => expect()->calling(static fn(): int => 1)
            ->consistently()->because('the result must be valid')
            ->for(0.01)->toReturn(1)->toReturn(2)];
        yield 'temporal replacement' => [static fn() => expect()->calling(static fn(): int => 1)
            ->because('the first reason')->returnValue()->eventually()->within(0.01)
            ->because('the result must be valid')->toBe(1)->toBe(2)];
        yield 'call replacement' => [static fn() => expect()->calling(static fn(): int => 1)
            ->because('the first reason')->toReturn(1)
            ->because('the result must be valid')->toReturn(1)->toReturn(2)];
    }

    #[Test]
    public function aThrowableCallbackKeepsItsOwnFailureReason(): void
    {
        $detail = FailureProbe::detailOf(static fn() => expect()->calling(static fn() => throw new \RuntimeException('ready'))
            ->because('the outer reason')
            ->toThrow(static function (\RuntimeException $error): void {
                expect($error->getMessage())->because('the inner reason')->toBe('failed');
            }));

        expect($detail->message)->toBe("Expected 'ready' to be 'failed' because the inner reason.");
    }
}
