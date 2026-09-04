<?php

declare(strict_types=1);

namespace Greenlight\Sandbox;

use Greenlight\Harness\Disposable;

/**
 * Registers autoloaders and unregisters them when the test scope closes.
 */
final class Autoloaders implements Disposable
{
    /** @var list<callable(string): void> */
    private array $autoloaders = [];

    /** @param callable(string): void $autoloader */
    public function register(callable $autoloader): void
    {
        $count = \count(\spl_autoload_functions());
        \spl_autoload_register($autoloader);

        if (\count(\spl_autoload_functions()) > $count) {
            $this->autoloaders[] = $autoloader;
        }
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
