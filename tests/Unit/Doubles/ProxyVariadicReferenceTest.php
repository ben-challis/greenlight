<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Doubles;

use Greenlight\Attribute\Test;
use Greenlight\Doubles\Doubles;
use Greenlight\Doubles\MockPlan;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Tests\Fixture\Doubles\VariadicReference;

final readonly class ProxyVariadicReferenceTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function callbacksCanChangeEveryVariadicReferenceArgument(): void
    {
        $doubles = new Doubles($this->tempDirectory->subdirectory('proxies'));
        $double = $doubles->mock(VariadicReference::class, static function (MockPlan $plan): void {
            $plan->expects('mutate')
                ->andReturnsUsing(static function (string &...$values): void {
                    foreach ($values as &$value) {
                        $value .= ' changed';
                    }
                });
        });
        $first = 'first';
        $second = 'second';

        try {
            $double->mutate($first, $second);

            Expect::that([$first, $second])
                ->because('a proxy callback MUST preserve every variadic reference')
                ->toBe(['first changed', 'second changed']);
        } finally {
            $doubles->dispose();
        }
    }
}
