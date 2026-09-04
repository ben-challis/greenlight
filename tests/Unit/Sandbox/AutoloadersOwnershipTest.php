<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Sandbox;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\Autoloaders;

final class AutoloadersOwnershipTest
{
    #[Test]
    public function disposalPreservesAnExistingAutoloader(): void
    {
        $calls = [];
        $loader = static function (string $class) use (&$calls): void {
            $calls[] = $class;
        };
        \spl_autoload_register($loader);
        $sandbox = new Autoloaders();

        try {
            $sandbox->register($loader);
            $sandbox->dispose();
            \class_exists('GreenlightExistingAutoloaderProbe');

            Expect::that($calls)->toBe(['GreenlightExistingAutoloaderProbe']);
        } finally {
            $sandbox->dispose();
            \spl_autoload_unregister($loader);
        }
    }

    #[Test]
    public function nestedSandboxDisposalPreservesTheOuterAutoloader(): void
    {
        $calls = [];
        $loader = static function (string $class) use (&$calls): void {
            $calls[] = $class;
        };
        $outer = new Autoloaders();
        $inner = new Autoloaders();

        try {
            $outer->register($loader);
            $inner->register($loader);
            $inner->dispose();
            \class_exists('GreenlightOuterAutoloaderProbe');
            Expect::that($calls)->toBe(['GreenlightOuterAutoloaderProbe']);

            $outer->dispose();
            $calls = [];
            \class_exists('GreenlightClosedAutoloaderProbe');
            Expect::that($calls)->toBe([]);
        } finally {
            $inner->dispose();
            $outer->dispose();
        }
    }
}
