<?php

declare(strict_types=1);

namespace Greenlight\Tools\Fuzz;

/**
 * Defines the PHP-Fuzzer configuration methods that Greenlight targets use.
 *
 * @internal
 */
interface FuzzerConfiguration
{
    /**
     * @param list<class-string<\Throwable>> $allowedExceptions
     */
    public function setAllowedExceptions(array $allowedExceptions): void;

    public function setMaxLen(int $maxLen): void;

    public function setTarget(\Closure $target): void;
}
