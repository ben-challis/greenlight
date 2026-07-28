<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Fixture;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\EnvironmentSandbox;

final class EnvironmentSandboxTest
{
    #[Test]
    public function setMakesTheVariableVisibleEverywhere(): void
    {
        $name = 'GREENLIGHT_SANDBOX_TEST_SET';
        $sandbox = new EnvironmentSandbox();

        $sandbox->set($name, 'value');

        Expect::that(\getenv($name))->because('set makes the variable visible everywhere')->toBe('value')
            ->and($this->envValue($name))->toBe('value')
            ->and($this->serverValue($name))->toBe('value');

        $sandbox->dispose();

        Expect::that(\getenv($name))->because('set makes the variable visible everywhere')->toBeFalse()
            ->and($this->envHas($name))->toBeFalse()
            ->and($this->serverHas($name))->toBeFalse();
    }

    #[Test]
    public function disposeRestoresThePriorValue(): void
    {
        $name = 'GREENLIGHT_SANDBOX_TEST_RESTORE';
        \putenv($name . '=original');
        $_ENV[$name] = 'original';
        $_SERVER[$name] = 'original';

        try {
            $sandbox = new EnvironmentSandbox();
            $sandbox->set($name, 'changed');
            $sandbox->dispose();

            Expect::that(\getenv($name))->toBe('original')
                ->and($this->envValue($name))->toBe('original')
                ->and($this->serverValue($name))->toBe('original');
        } finally {
            \putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        }
    }

    #[Test]
    public function disposePreservesPresentFalseyOriginalValues(): void
    {
        $name = 'GREENLIGHT_SANDBOX_TEST_FALSEY_RESTORE';
        \putenv($name . '=');
        $_ENV[$name] = null;
        $_SERVER[$name] = false;

        try {
            $sandbox = new EnvironmentSandbox();
            $sandbox->set($name, 'changed');
            $sandbox->dispose();

            Expect::that(\getenv($name))
                ->because('dispose MUST distinguish present falsey values from absent values')
                ->toBe('')
                ->and($this->envHas($name))
                ->toBeTrue()
                ->and($this->envValue($name))
                ->toBeNull()
                ->and($this->serverHas($name))
                ->toBeTrue()
                ->and($this->serverValue($name))
                ->toBeFalse();
        } finally {
            \putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        }
    }

    #[Test]
    public function disposeRestoresEachEnvironmentChannelIndependently(): void
    {
        $suffix = \strtoupper(\bin2hex(\random_bytes(6)));
        $processAndServer = 'GREENLIGHT_SANDBOX_TEST_PROCESS_SERVER_' . $suffix;
        $envOnly = 'GREENLIGHT_SANDBOX_TEST_ENV_ONLY_' . $suffix;
        $sandbox = new EnvironmentSandbox();

        try {
            \putenv($processAndServer . '=process-original');
            unset($_ENV[$processAndServer]);
            $_SERVER[$processAndServer] = 'server-original';

            \putenv($envOnly);
            $_ENV[$envOnly] = 'env-original';
            unset($_SERVER[$envOnly]);

            $sandbox->set($processAndServer, 'changed');
            $sandbox->unset($envOnly);
            $sandbox->dispose();

            Expect::that(\getenv($processAndServer))
                ->because('dispose restores each environment channel independently')
                ->toBe('process-original')
                ->and($this->envHas($processAndServer))
                ->toBeFalse()
                ->and($this->serverValue($processAndServer))
                ->toBe('server-original')
                ->and(\getenv($envOnly))
                ->toBeFalse()
                ->and($this->envValue($envOnly))
                ->toBe('env-original')
                ->and($this->serverHas($envOnly))
                ->toBeFalse();
        } finally {
            $sandbox->dispose();
            \putenv($processAndServer);
            \putenv($envOnly);
            unset(
                $_ENV[$processAndServer],
                $_ENV[$envOnly],
                $_SERVER[$processAndServer],
                $_SERVER[$envOnly],
            );
        }
    }

    #[Test]
    public function unsetRemovesTheVariableAndDisposeBringsItBack(): void
    {
        $name = 'GREENLIGHT_SANDBOX_TEST_UNSET';
        \putenv($name . '=present');
        $_ENV[$name] = 'present';
        $_SERVER[$name] = 'present';

        try {
            $sandbox = new EnvironmentSandbox();
            $sandbox->unset($name);

            Expect::that(\getenv($name))->toBeFalse()
                ->and($this->envHas($name))->toBeFalse()
                ->and($this->serverHas($name))->toBeFalse();

            $sandbox->dispose();

            Expect::that(\getenv($name))->toBe('present')
                ->and($this->envValue($name))->toBe('present')
                ->and($this->serverValue($name))->toBe('present');
        } finally {
            \putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        }
    }

    #[Test]
    public function theFirstRecordedOriginalWinsAcrossMultipleSets(): void
    {
        $name = 'GREENLIGHT_SANDBOX_TEST_FIRST_WINS';
        \putenv($name . '=first');
        $_ENV[$name] = 'first';
        $_SERVER[$name] = 'first';

        try {
            $sandbox = new EnvironmentSandbox();
            $sandbox->set($name, 'second');
            $sandbox->set($name, 'third');
            $sandbox->unset($name);
            $sandbox->dispose();

            Expect::that(\getenv($name))->toBe('first')
                ->and($this->envValue($name))->toBe('first')
                ->and($this->serverValue($name))->toBe('first');
        } finally {
            \putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        }
    }

    /** Tells the analyzer that the superglobal offset remains variable after a change. */
    private function envValue(string $name): mixed
    {
        return $_ENV[$name] ?? null;
    }

    private function serverValue(string $name): mixed
    {
        return $_SERVER[$name] ?? null;
    }

    private function envHas(string $name): bool
    {
        return \array_key_exists($name, $_ENV);
    }

    private function serverHas(string $name): bool
    {
        return \array_key_exists($name, $_SERVER);
    }
}
