<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Tempest;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Harness\Scope;
use Greenlight\Plugin\TestContext;
use Greenlight\Result\TestResult;
use Greenlight\Tempest\TempestPlugin;
use Greenlight\Tests\Support\PluginLifecycle;
use Tempest\Container\Container;
use Tempest\Core\Kernel;

final readonly class TempestPluginConfigurationTest
{
    #[Test]
    public function exposesTheKernelAndContainerAsPerWorkerServices(): void
    {
        $definitions = new TempestPlugin('/project')->services();

        Expect::that($definitions)->toHaveCount(2);
        Expect::that($definitions[0]->type)->toBe(Kernel::class);
        Expect::that($definitions[0]->scope)->toBe(Scope::PerWorker);
        Expect::that($definitions[1]->type)->toBe(Container::class);
        Expect::that($definitions[1]->scope)->toBe(Scope::PerWorker);
    }

    #[Test]
    public function lifecycleHooksDoNotBootAnUnusedKernel(): void
    {
        $plugin = new TempestPlugin('/project');
        $context = $this->context();
        $result = $this->result();

        $plugin->beforeTest($context);

        Expect::that($plugin->afterTest($context, $result))
            ->because('an unused Tempest bridge MUST preserve the test result')
            ->toBe($result);
    }

    #[Test]
    public function rejectsAnEmptyApplicationRoot(): void
    {
        Expect::that(static fn(): TempestPlugin => new TempestPlugin(''))
            ->toThrow(\InvalidArgumentException::class, message: 'Tempest application root MUST NOT be empty.');
    }

    #[Test]
    public function rejectsAnEmptyEnvironment(): void
    {
        Expect::that(static fn(): TempestPlugin => new TempestPlugin('/project', environment: ''))
            ->toThrow(\InvalidArgumentException::class, message: 'Tempest environment MUST NOT be empty.');
    }

    private function context(): TestContext
    {
        return PluginLifecycle::context();
    }

    private function result(): TestResult
    {
        return PluginLifecycle::passedResult();
    }
}
