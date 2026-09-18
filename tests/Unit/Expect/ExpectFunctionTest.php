<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Expect;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Test\Cleanup;
use Greenlight\Tests\Fixture\Expect\EvenNumbersExtension;

use function Greenlight\expect;

final readonly class ExpectFunctionTest
{
    public function __construct(private Cleanup $cleanup) {}

    #[Test]
    public function callableSubjectsRemainValues(): void
    {
        $call = static fn(): never => throw new \LogicException('The value must not execute.');

        expect($call)->toBe($call)->toBeCallable();
    }

    #[Test]
    public function anExplicitNullRemainsAValue(): void
    {
        expect(null)->toBeNull();
    }

    #[Test]
    public function theBuilderSelectsALazyCallWithOneCapturedOutcome(): void
    {
        $calls = 0;
        $call = expect()->calling(static function () use (&$calls): int {
            ++$calls;

            return 7;
        });

        Expect::value($calls)->toBe(0);
        $call->toReturn(7)->returnValue()->toBeGreaterThan(6);
        Expect::value($calls)->toBe(1);
    }

    #[Test]
    public function theBuilderSupportsThrowableAssertions(): void
    {
        expect()->calling(static fn(): never => throw new \RuntimeException('Expected failure.'))
            ->toThrow(\RuntimeException::class, message: 'Expected failure.');
    }

    #[Test]
    public function helperChainsKeepTheConfiguredExtensions(): void
    {
        $this->cleanup->defer(Expect::install([new EvenNumbersExtension()]));
        $chain = expect(4);
        Expect::install([]);

        $chain->toBeEven();
    }

    #[Test]
    public function failureLocationPointsAtTheMatcherCall(): void
    {
        $line = __LINE__ + 1;
        $detail = FailureProbe::detailOf(static fn() => expect(1)->toBe(2));

        Expect::value($detail->location?->file)->toBe(__FILE__);
        Expect::value($detail->location?->line)->toBe($line);
    }
}
