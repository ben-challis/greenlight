<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Sandbox;

use Greenlight\Attribute\Test;
use Greenlight\Sandbox\Autoloaders;

use function Greenlight\expect;

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

            expect($calls)->toBe([
                'first:GreenlightAutoloadersBeforeDisposal',
                'second:GreenlightAutoloadersBeforeDisposal',
            ]);

            $sandbox->dispose();
            $calls = [];
            \class_exists('GreenlightAutoloadersAfterDisposal');

            expect($calls)
                ->because('disposal MUST remove all autoloaders that the sandbox owns')
                ->toBe([]);
        } finally {
            $sandbox->dispose();
        }
    }
}
