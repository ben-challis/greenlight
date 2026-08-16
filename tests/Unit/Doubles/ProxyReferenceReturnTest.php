<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Doubles;

use Greenlight\Attribute\Test;
use Greenlight\Doubles\Doubles;
use Greenlight\Doubles\MockPlan;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Tests\Fixture\Doubles\ReferenceReturn;

final readonly class ProxyReferenceReturnTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function generatedProxiesPreserveReferenceReturnSignatures(): void
    {
        $doubles = new Doubles($this->tempDirectory->subdirectory('proxies'));
        $double = $doubles->mock(ReferenceReturn::class, static function (MockPlan $plan): void {
            $plan->expects('value')->andReturns('answer');
        });

        try {
            $method = new \ReflectionMethod($double, 'value');

            Expect::that($method->returnsReference())
                ->because('a proxy method MUST preserve its by-reference return signature')
                ->toBeTrue();
            Expect::that($double->value())
                ->toBe('answer');
        } finally {
            $doubles->dispose();
        }
    }
}
