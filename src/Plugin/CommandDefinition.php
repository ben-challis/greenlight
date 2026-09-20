<?php

declare(strict_types=1);

namespace Greenlight\Plugin;

/** Defines one named command-line command. */
final readonly class CommandDefinition
{
    /** @var non-empty-string */
    public string $name;

    /** @var non-empty-string */
    public string $description;

    /**
     * @param \Closure(CommandInvocation): CommandResult $handler
     * @phpstan-assert non-empty-string $name
     * @phpstan-assert non-empty-string $description
     *
     * @throws \InvalidArgumentException
     */
    public function __construct(
        string $name,
        string $description,
        private \Closure $handler,
    ) {
        if (\preg_match('/^[a-z][a-z0-9]*(?:[:-][a-z0-9]+)*$/D', $name) !== 1) {
            throw new \InvalidArgumentException(
                'Start command names with a lowercase ASCII letter. Use lowercase ASCII letters and digits, with single hyphens or colons between segments.',
            );
        }

        if ($description === '' || \str_contains($description, "\n") || \str_contains($description, "\r")) {
            throw new \InvalidArgumentException('Use a non-empty single-line string for each command description.');
        }

        $this->name = $name;
        $this->description = $description;
    }

    /** @internal Greenlight runs command definitions. */
    public function run(CommandInvocation $invocation): CommandResult
    {
        return ($this->handler)($invocation);
    }
}
