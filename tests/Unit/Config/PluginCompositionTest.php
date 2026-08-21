<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Config;

use Greenlight\Attribute\Test;
use Greenlight\Config\GreenlightConfig;
use Greenlight\Expect\Expect;
use Greenlight\Plugin\PluginDefinition;
use Greenlight\Tests\Fixture\Plugins\NamedFakePlugin;

final readonly class PluginCompositionTest
{
    #[Test]
    public function repeatedPluginCallsAppendInConfigurationOrder(): void
    {
        $first = new PluginDefinition(
            NamedFakePlugin::class,
            static fn(): NamedFakePlugin => new NamedFakePlugin(),
        );
        $second = new PluginDefinition(
            NamedFakePlugin::class,
            static fn(): NamedFakePlugin => new NamedFakePlugin(),
        );

        $plugins = GreenlightConfig::create()
            ->plugins($first)
            ->plugins($second)
            ->build()
            ->plugins;

        Expect::that($plugins)
            ->because('repeated plugin calls MUST retain every configured plugin in order')
            ->toBe([$first, $second]);
    }
}
