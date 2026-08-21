<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Doubles;

use Greenlight\Attribute\Test;
use Greenlight\Doubles\Doubles;
use Greenlight\Doubles\MockPlan;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Fixture\Doubles\OptionalReference;

final readonly class ProxyOptionalReferenceTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function callbacksReceiveOnlySuppliedOptionalReferenceArguments(): void
    {
        $doubles = new Doubles($this->tempDirectory->subdirectory('proxies'));
        $double = $doubles->mock(OptionalReference::class, static function (MockPlan $plan): void {
            $plan->expects('supplied')
                ->andReturnsUsing(static function (?string &$value = null): int {
                    $count = \func_num_args();

                    if ($count === 1) {
                        $value = 'changed';
                    }

                    return $count;
                });
        });
        $value = 'original';

        try {
            $omitted = $double->supplied();
            $provided = $double->supplied($value);

            Expect::that($omitted)
                ->because('a proxy callback MUST NOT receive an omitted optional argument')
                ->toBe(0);
            Expect::that($provided)
                ->because('a proxy callback MUST receive a supplied optional argument')
                ->toBe(1);
            Expect::that($value)
                ->because('a proxy callback MUST preserve a supplied optional reference')
                ->toBe('changed');
        } finally {
            $doubles->dispose();
        }
    }
}
