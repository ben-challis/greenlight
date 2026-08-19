<?php

declare(strict_types=1);

namespace Greenlight\Tests\Support;

final class ClassFile
{
    private function __construct() {}

    /**
     * @param class-string $class
     *
     * @return non-empty-string
     */
    public static function of(string $class): string
    {
        $file = new \ReflectionClass($class)->getFileName();

        if ($file === false) {
            throw new \RuntimeException(\sprintf('Class "%s" does not have a source file.', $class));
        }

        return $file;
    }
}
