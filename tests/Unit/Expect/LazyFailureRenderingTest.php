<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Expect;

use Greenlight\Attribute\Test;
use Greenlight\Tests\Fixture\Expect\Point;

use function Greenlight\expect;

final class LazyFailureRenderingTest
{
    #[Test]
    public function passingMatchersDoNotRenderTheirValues(): void
    {
        $initializations = 0;
        $ghost = new \ReflectionClass(Point::class)->newLazyProxy(
            static function () use (&$initializations): Point {
                ++$initializations;

                return new Point(1, 2);
            },
        );

        expect($ghost)->toBe($ghost);
        expect([$ghost])->toContain($ghost);
        expect($ghost)->toBeOneOf($ghost);
        expect($ghost)->toBeIn([$ghost]);
        expect(['key' => $ghost])->toHaveKey('key');
        expect(1)->not()->toBe($ghost);

        expect($initializations)
            ->because('a passing matcher MUST NOT render values, so a lazy object stays uninitialized')
            ->toBe(0);
    }

    #[Test]
    public function failingMatchersStillRenderTheirValues(): void
    {
        $detail = FailureProbe::detailOf(static fn() => expect(1)->toBe(new Point(1, 2)));

        expect($detail->message)
            ->because('a failure renders the expected value')
            ->toBe('Expected 1 to be ' . Point::class . ' {x: 1, y: 2}.');
    }
}
