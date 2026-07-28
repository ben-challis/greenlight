<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Doubles;

use Greenlight\Attribute\Test;
use Greenlight\Doubles\Doubles;
use Greenlight\Doubles\MockPlan;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Tests\Fixture\Doubles\GlobalConstantDefault;

final readonly class ProxyGlobalConstantDefaultTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function globalConstantDefaultsRemainConstantsOnGeneratedMethods(): void
    {
        $doubles = new Doubles($this->tempDirectory->subdirectory('proxies'));
        $double = $doubles->mock(GlobalConstantDefault::class, static function (MockPlan $plan): void {
            $plan->expects('limit')->once()->andReturns(42);
        });
        $parameter = new \ReflectionMethod($double, 'limit')->getParameters()[0];

        try {
            $answer = $double->limit();

            Expect::that($parameter->getDefaultValueConstantName())
                ->because('a generated method MUST preserve its global default constant')
                ->toBe('PHP_INT_MAX')
                ->and($parameter->getDefaultValue())
                ->toBe(\PHP_INT_MAX)
                ->and($answer)
                ->toBe(42);
        } finally {
            $doubles->dispose();
        }
    }
}
