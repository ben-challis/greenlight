<?php

declare(strict_types=1);

namespace Greenlight\Cli;

/**
 * Declares one long option the parser accepts, with an optional single-letter
 * alias.
 *
 * @internal
 */
final readonly class OptionSpec
{
    /**
     * @param non-empty-string $name
     * @param non-empty-string|null $short
     */
    public function __construct(
        public string $name,
        public OptionValue $value = OptionValue::None,
        public bool $repeatable = false,
        public ?string $short = null,
    ) {}
}
