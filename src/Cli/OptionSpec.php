<?php

declare(strict_types=1);

namespace Greenlight\Cli;

/** @internal */
final readonly class OptionSpec
{
    /**
     * @var non-empty-string
     */
    public string $name;

    /**
     * @var non-empty-string|null
     */
    public ?string $short;

    /**
     * @throws \InvalidArgumentException when the option name or short alias is invalid
     */
    public function __construct(
        string $name,
        public OptionValue $value = OptionValue::None,
        public bool $repeatable = false,
        ?string $short = null,
    ) {
        if ($name === '') {
            throw new \InvalidArgumentException('Option names cannot be empty.');
        }

        if ($short !== null && \preg_match('/^[A-Za-z]$/D', $short) !== 1) {
            throw new \InvalidArgumentException(\sprintf(
                'Short option alias "%s" MUST be one ASCII letter.',
                $short,
            ));
        }

        $this->name = $name;
        $this->short = $short;
    }
}
