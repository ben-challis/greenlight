<?php

declare(strict_types=1);

namespace Greenlight\Cli;

/** @internal */
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
