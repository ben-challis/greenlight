<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Sandbox;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\Autoloaders;

final readonly class AutoloadersTest
{
    #[Test]
    public function disposalUnregistersEveryOwnedAutoloader(): void
    {
        $sandbox = new Autoloaders();
        $calls = [];
        $first = static function (string $class) use (&$calls): void {
            $calls[] = 'first:' . $class;
        };
        $second = static function (string $class) use (&$calls): void {
            $calls[] = 'second:' . $class;
        };
        $sandbox->register($first);
        $sandbox->register($second);

        try {
            \class_exists('GreenlightAutoloadersBeforeDisposal');

            Expect::that($calls)->toBe([
                'first:GreenlightAutoloadersBeforeDisposal',
                'second:GreenlightAutoloadersBeforeDisposal',
            ]);

            $sandbox->dispose();
            $calls = [];
            \class_exists('GreenlightAutoloadersAfterDisposal');

            Expect::that($calls)
                ->because('disposal MUST remove all autoloaders that the sandbox owns')
                ->toBe([]);
        } finally {
            $sandbox->dispose();
        }
    }
}
