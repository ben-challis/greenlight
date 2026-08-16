<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Fixture;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\EnvironmentSandbox;

final readonly class EnvironmentSandboxReuseTest
{
    #[Test]
    public function useAfterDisposalCapturesANewBaseline(): void
    {
        $name = 'GREENLIGHT_SANDBOX_REUSE_' . \strtoupper(\bin2hex(\random_bytes(6)));
        $sandbox = new EnvironmentSandbox();

        try {
            $sandbox->set($name, 'first session');
            $sandbox->dispose();

            \putenv($name . '=new baseline');
            $_ENV[$name] = 'new baseline';
            $_SERVER[$name] = 'new baseline';

            $sandbox->set($name, 'second session');
            $sandbox->dispose();

            Expect::that(\getenv($name))
                ->because('use after disposal MUST capture the new environment baseline')
                ->toBe('new baseline');
            Expect::that($_ENV[$name] ?? null)
                ->toBe('new baseline');
            Expect::that($_SERVER[$name] ?? null)
                ->toBe('new baseline');
        } finally {
            $sandbox->dispose();
            \putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        }
    }
}
