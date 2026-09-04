<?php

declare(strict_types=1);

namespace Greenlight\Config;

/** Configures a named suite. */
final class SuiteBuilder
{
    /**
     * @var list<non-empty-string>
     */
    private array $paths = [];

    /**
     * @var list<non-empty-string>
     */
    private array $tags = [];

    /**
     * @param non-empty-string $name
     */
    public function __construct(private readonly string $name) {}

    /**
     * @param non-empty-string ...$paths
     *
     * @throws InvalidConfiguration
     */
    public function in(string ...$paths): self
    {
        $validated = [];

        foreach ($paths as $path) {
            if ($path === '') {
                throw InvalidConfiguration::emptySuitePath($this->name);
            }

            if (\str_contains($path, "\0")) {
                throw InvalidConfiguration::suitePathContainsNullByte($this->name);
            }

            $validated[] = $path;
        }

        $this->paths = [...$this->paths, ...$validated];

        return $this;
    }

    /**
     * @param non-empty-string ...$tags
     *
     * @throws InvalidConfiguration
     */
    public function tag(string ...$tags): self
    {
        $validated = [];

        foreach ($tags as $tag) {
            if ($tag === '') {
                throw InvalidConfiguration::emptySuiteTag($this->name);
            }

            $validated[] = $tag;
        }

        $this->tags = [...$this->tags, ...$validated];

        return $this;
    }

    /**
     * @internal
     *
     * @throws InvalidConfiguration if the suite has no paths
     */
    public function toConfiguration(): SuiteConfiguration
    {
        if ($this->paths === []) {
            throw InvalidConfiguration::missingSuitePaths($this->name);
        }

        return new SuiteConfiguration($this->name, $this->paths, $this->tags);
    }
}
