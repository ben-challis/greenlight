<?php

declare(strict_types=1);

namespace Greenlight\Runner\Resource;

use Greenlight\Core\ErrorTrap;

/**
 * Holds the file handles for machine resource slots in one class assignment.
 *
 * @internal
 */
final class MachineResourcePermit
{
    private bool $released = false;

    /**
     * @param array<non-empty-string, resource> $handles
     * @param list<non-empty-string> $coordinationKeys
     */
    public function __construct(
        private array $handles,
        public readonly array $coordinationKeys,
    ) {}

    public function close(): void
    {
        if ($this->released) {
            return;
        }

        $this->released = true;

        foreach ($this->handles as $handle) {
            ErrorTrap::run(static function () use ($handle): void {
                \flock($handle, \LOCK_UN);
                \fclose($handle);
            });
        }

        $this->handles = [];
    }

    public function __destruct()
    {
        $this->close();
    }
}
