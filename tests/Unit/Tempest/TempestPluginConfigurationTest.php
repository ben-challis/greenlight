<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Tempest;

use Greenlight\Attribute\Test;
use Greenlight\Core\Result\Outcome;
use Greenlight\Core\Result\TestResult;
use Greenlight\Core\Test\TestId;
use Greenlight\Core\Test\TestMetadata;
use Greenlight\Expect\Expect;
use Greenlight\Harness\HarnessRegistry;
use Greenlight\Harness\HarnessScopes;
use Greenlight\Harness\Scope;
use Greenlight\Plugin\TestContext;
use Greenlight\Tempest\TempestPlugin;
use Tempest\Container\Container;
use Tempest\Core\Kernel;

final readonly class TempestPluginConfigurationTest
{
    #[Test]
    public function exposesTheKernelAndContainerAsPerRunServices(): void
    {
        $definitions = new TempestPlugin('/project')->services();

        Expect::that($definitions)->toHaveCount(2);
        Expect::that($definitions[0]->type)->toBe(Kernel::class);
        Expect::that($definitions[0]->scope)->toBe(Scope::PerRun);
        Expect::that($definitions[1]->type)->toBe(Container::class);
        Expect::that($definitions[1]->scope)->toBe(Scope::PerRun);
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
        return new TestContext(
            new \stdClass(),
            new TestId('Fixture', 'probe'),
            new TestMetadata('Fixture', 'probe'),
            new HarnessScopes(new HarnessRegistry()),
        );
    }

    private function result(): TestResult
    {
        return new TestResult(new TestId('Fixture', 'probe'), Outcome::Passed, 0.0, 0);
    }
}
