<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Discovery;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\PhpSubprocess;

final readonly class DiscoveryCacheInheritanceTest
{
    public function __construct(private TemporaryDirectory $temporaryDirectory) {}

    #[Test]
    #[DataSet("inheritedSources")]
    public function sourceChangesUpdateTheCachedPlan(
        string $testSource,
        string $dependencyName,
        string $dependencySource,
        string $replacementSource,
        string $before,
        string $after,
    ): void {
        $directory = $this->temporaryDirectory->subdirectory("inherited-cache");
        \file_put_contents($directory . "/InheritedTest.php", "<?php namespace CacheInheritance; " . $testSource);
        \file_put_contents($directory . "/" . $dependencyName . ".php", "<?php namespace CacheInheritance; " . $dependencySource);
        $script = $directory . "/discover.php";
        \file_put_contents($script, <<<'PHP'
            <?php
            require $argv[1];
            spl_autoload_register(static function (string $class): void {
                $prefix = "CacheInheritance\\";
                if (str_starts_with($class, $prefix)) {
                    require __DIR__ . "/" . substr($class, strlen($prefix)) . ".php";
                }
            });
            $plan = new \Greenlight\Discovery\TestDiscoverer()->discover(
                [__DIR__],
                cache: \Greenlight\Discovery\DiscoveryCache::forDirectories([__DIR__], __DIR__),
            );
            foreach ($plan->entries as $entry) {
                echo $entry->id, "\n";
            }
            PHP);
        $arguments = [$script, \dirname(__DIR__, 3) . "/vendor/autoload.php"];
        $cold = PhpSubprocess::run($directory, $arguments);
        Expect::that($cold->exitCode)->toBe(0);
        Expect::that($cold->stdout)->toBe($before);

        $warm = PhpSubprocess::run($directory, $arguments);
        Expect::that($warm->exitCode)->toBe(0);
        Expect::that($warm->stdout)->toBe($before);

        \file_put_contents($directory . "/" . $dependencyName . ".php", "<?php namespace CacheInheritance; " . $replacementSource);
        $changed = PhpSubprocess::run($directory, $arguments);
        Expect::that($changed->exitCode)->toBe(0);
        Expect::that($changed->stdout)->toBe($after);
    }

    /** @return iterable<string, array{string, string, string, string, string, string}> */
    public static function inheritedSources(): iterable
    {
        yield "parent test method" => [
            "class InheritedTest extends Base {}",
            "Base",
            "class Base { #[\\Greenlight\\Attribute\\Test] public function original(): void {} }",
            "class Base { #[\\Greenlight\\Attribute\\Test] public function replacement(): void {} }",
            "CacheInheritance\\InheritedTest::original",
            "CacheInheritance\\InheritedTest::replacement",
        ];
        yield "trait test method" => [
            "class InheritedTest { use SharedMethods; }",
            "SharedMethods",
            "trait SharedMethods { #[\\Greenlight\\Attribute\\Test] public function original(): void {} }",
            "trait SharedMethods { #[\\Greenlight\\Attribute\\Test] public function replacement(): void {} }",
            "CacheInheritance\\InheritedTest::original",
            "CacheInheritance\\InheritedTest::replacement",
        ];
        yield "inherited local provider" => [
            "class InheritedTest extends Base { #[\\Greenlight\\Attribute\\Test] #[\\Greenlight\\Attribute\\DataSet(\"rows\")] public function probe(int \$value): void {} }",
            "Base",
            "class Base { public static function rows(): iterable { yield \"original\" => [1]; } }",
            "class Base { public static function rows(): iterable { yield \"replacement\" => [2]; } }",
            "CacheInheritance\\InheritedTest::probe[original]",
            "CacheInheritance\\InheritedTest::probe[replacement]",
        ];
        yield "previously empty parent" => [
            "class InheritedTest extends Base {}",
            "Base",
            "class Base {}",
            "class Base { #[\\Greenlight\\Attribute\\Test] public function added(): void {} }",
            "",
            "CacheInheritance\\InheritedTest::added",
        ];
    }
}
