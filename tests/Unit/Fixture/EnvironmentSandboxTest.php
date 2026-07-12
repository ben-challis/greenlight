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

        Expect::that(\getenv($name))->toBe('value')
            ->and($this->envValue($name))->toBe('value')
            ->and($this->serverValue($name))->toBe('value');

        $sandbox->dispose();

        Expect::that(\getenv($name))->toBeFalse()
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

    /**
     * Reads through a parameter so static analysis cannot narrow the offset:
     * the sandbox mutates the superglobals behind the analyser's back.
     */
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
