<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Support;

use Greenlight\Attribute\Test;
use Greenlight\Core\Result\Outcome;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Support\PluginLifecycle;

final readonly class PluginLifecycleTest
{
    #[Test]
    public function createsFreshAlignedPassedLifecycleValues(): void
    {
        $context = PluginLifecycle::context();
        $result = PluginLifecycle::passedResult();

        Expect::that(PluginLifecycle::context())
            ->because('each plugin test MUST receive independent lifecycle state')
            ->not()
            ->toBe($context);
        Expect::that($context->id->equals($result->id))
            ->because('the shared context and result MUST identify the same test')
            ->toBeTrue();
        Expect::that($context->metadata->class)->toBe($context->id->class);
        Expect::that($context->metadata->method)->toBe($context->id->method);
        Expect::that($result->outcome)->toBe(Outcome::Passed);
    }
}
