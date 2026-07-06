<?php

declare(strict_types=1);

namespace Greenlight\Discovery;

/**
 * A class-like declaration found by token parsing a file, before any code
 * from that file has been loaded.
 *
 * @internal
 */
final readonly class ClassDeclaration
{
    /**
     * @param 'class'|'enum'|'interface'|'trait' $kind
     */
    public function __construct(
        public string $namespace,
        public string $shortName,
        public string $kind,
    ) {}

    public function fqcn(): string
    {
        if ($this->namespace === '') {
            return $this->shortName;
        }

        return $this->namespace . '\\' . $this->shortName;
    }
}
