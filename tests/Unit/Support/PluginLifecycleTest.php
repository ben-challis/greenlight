<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Support;

use Greenlight\Attribute\Test;
use Greenlight\Result\Outcome;
use Greenlight\Tests\Support\PluginLifecycle;

use function Greenlight\expect;

final readonly class PluginLifecycleTest
{
    #[Test]
    public function createsFreshAlignedPassedLifecycleValues(): void
    {
        $context = PluginLifecycle::context();
        $result = PluginLifecycle::passedResult();

        expect(PluginLifecycle::context())
            ->because('each plugin test MUST receive independent lifecycle state')
            ->not()->toBe($context);
        expect($context->id->equals($result->id))
            ->because('the shared context and result MUST identify the same test')
            ->toBeTrue();
        expect($context->definition->class)->toBe($context->id->class);
        expect($context->definition->method)->toBe($context->id->method);
        expect($result->outcome)->toBe(Outcome::Passed);
    }
}
