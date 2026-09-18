<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Support;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Result\Outcome;
use Greenlight\Tests\Support\PluginLifecycle;

final readonly class PluginLifecycleTest
{
    #[Test]
    public function createsFreshAlignedPassedLifecycleValues(): void
    {
        $context = PluginLifecycle::context();
        $result = PluginLifecycle::passedResult();

        Expect::value(PluginLifecycle::context())
            ->because('each plugin test MUST receive independent lifecycle state')
            ->not()
            ->toBe($context);
        Expect::value($context->id->equals($result->id))
            ->because('the shared context and result MUST identify the same test')
            ->toBeTrue();
        Expect::value($context->definition->class)->toBe($context->id->class);
        Expect::value($context->definition->method)->toBe($context->id->method);
        Expect::value($result->outcome)->toBe(Outcome::Passed);
    }
}
