<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Doubles;

use Greenlight\Attribute\Test;
use Greenlight\Doubles\Doubles;
use Greenlight\Doubles\MockPlan;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Tests\Fixture\Doubles\ParentConstantDefault;
use Greenlight\Tests\Fixture\Doubles\ParentConstantDefaultBase;

final readonly class ProxyParentConstantDefaultTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function parentConstantDefaultsResolveAgainstTheParentType(): void
    {
        $doubles = new Doubles($this->tempDirectory->subdirectory('proxies'));
        $double = $doubles->mock(ParentConstantDefault::class, static function (MockPlan $plan): void {
            $plan->expects('mode')->once()->andReturns('answered');
        });
        $parameter = new \ReflectionMethod($double, 'mode')->getParameters()[0];

        try {
            $answer = $double->mode();

            Expect::that($parameter->getDefaultValueConstantName())
                ->because('a generated method MUST resolve a parent constant against the parent type')
                ->toBe(ParentConstantDefaultBase::class . '::MODE')
                ->and($parameter->getDefaultValue())
                ->toBe('inherited')
                ->and($answer)
                ->toBe('answered');
        } finally {
            $doubles->dispose();
        }
    }
}
