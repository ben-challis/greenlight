<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli\Discovery;

use Greenlight\Attribute\Test;
use Greenlight\Cli\Configuration\CliOverrides;
use Greenlight\Cli\Configuration\ConfigurationResolver;
use Greenlight\Cli\Configuration\LoadedConfiguration;
use Greenlight\Cli\Discovery\SelectionPlan;
use Greenlight\Cli\State\RunState;
use Greenlight\Config\GreenlightConfig;
use Greenlight\Config\StorageBuilder;
use Greenlight\Config\StorageLayout;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Fixture\DiscoveryBasic\AlphaTest;
use Greenlight\Tests\Fixture\DiscoveryBasic\BravoTest;
use Greenlight\Tests\Fixture\DiscoveryBasic\CharlieTest;
use Greenlight\Tests\Fixture\DiscoveryBasic\DeltaTest;
use Greenlight\Tests\Support\FixturePath;

final readonly class SelectionPlanOrderTest
{
    public function __construct(private TemporaryDirectory $temporary) {}

    #[Test]
    public function repeatedFailuresKeepClassesInFirstFailureOrder(): void
    {
        $directory = FixturePath::get('DiscoveryBasic');
        $workingDirectory = $this->temporary->path();
        $configuration = GreenlightConfig::create()
            ->paths([$directory])
            ->storage(static fn(StorageBuilder $storage) => $storage->rootDirectory('state'))
            ->build();
        $overrides = new CliOverrides();
        $resolved = ConfigurationResolver::resolve($configuration, $overrides);
        $loaded = new LoadedConfiguration($resolved, $workingDirectory . '/greenlight.php', $overrides, [$directory]);
        $layout = StorageLayout::resolve($resolved->storage, $workingDirectory);
        Expect::that(RunState::forFile($layout->runStateFile)->record([
            'not-a-test-id',
            CharlieTest::class . '::crawls',
            AlphaTest::class . '::two',
            CharlieTest::class . '::crawls',
            AlphaTest::class . '::one',
        ]))->toBeTrue();

        $plan = SelectionPlan::resolve($loaded, $workingDirectory, false);

        Expect::that($plan->classes())->toBe([CharlieTest::class, AlphaTest::class, BravoTest::class, DeltaTest::class]);
        Expect::that($plan->count())->toBe(7);
    }
}
