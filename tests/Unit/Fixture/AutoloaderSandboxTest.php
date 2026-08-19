<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Fixture;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\AutoloaderSandbox;

final readonly class AutoloaderSandboxTest
{
    #[Test]
    public function disposalUnregistersEveryOwnedAutoloader(): void
    {
        $sandbox = new AutoloaderSandbox();
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
            \class_exists('GreenlightAutoloaderSandboxBeforeDisposal');

            Expect::that($calls)->toBe([
                'first:GreenlightAutoloaderSandboxBeforeDisposal',
                'second:GreenlightAutoloaderSandboxBeforeDisposal',
            ]);

            $sandbox->dispose();
            $calls = [];
            \class_exists('GreenlightAutoloaderSandboxAfterDisposal');

            Expect::that($calls)
                ->because('disposal MUST remove all autoloaders that the sandbox owns')
                ->toBe([]);
        } finally {
            $sandbox->dispose();
        }
    }
}
