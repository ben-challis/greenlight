<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Doubles;

use Greenlight\Attribute\Test;
use Greenlight\Doubles\Doubles;
use Greenlight\Doubles\MockPlan;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Tests\Fixture\Doubles\OptionalReference;

final readonly class ProxyOptionalReferenceTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

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

            Expect::that([$omitted, $provided, $value])
                ->because('a proxy callback MUST receive only arguments that the caller supplied')
                ->toBe([0, 1, 'changed']);
        } finally {
            $doubles->dispose();
        }
    }
}
