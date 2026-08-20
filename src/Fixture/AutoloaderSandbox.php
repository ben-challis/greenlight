<?php

declare(strict_types=1);

namespace Greenlight\Fixture;

use Greenlight\Harness\Disposable;

/**
 * Registers autoloaders and unregisters them when the test scope closes.
 */
final class AutoloaderSandbox implements Disposable
{
    /** @var list<callable(string): void> */
    private array $autoloaders = [];

    /** @param callable(string): void $autoloader */
    public function register(callable $autoloader): void
    {
        \spl_autoload_register($autoloader);
        $this->autoloaders[] = $autoloader;
    }

    #[\Override]
    public function dispose(): void
    {
        foreach (\array_reverse($this->autoloaders) as $autoloader) {
            \spl_autoload_unregister($autoloader);
        }

        $this->autoloaders = [];
    }
}
