<?php

declare(strict_types=1);

namespace Greenlight\Config;

/**
 * Fluent builder handed to suite configurators. Collects the paths a suite
 * covers and the tags it applies. Config files type-hint this class, so it
 * is part of the public configuration surface.
 */
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
     * @throws InvalidConfiguration
     */
    public function in(string ...$paths): self
    {
        foreach ($paths as $path) {
            if ($path === '') {
                throw new InvalidConfiguration(\sprintf('Suite "%s" was given an empty path.', $this->name));
            }

            $this->paths[] = $path;
        }

        return $this;
    }

    /**
     * @throws InvalidConfiguration
     */
    public function tag(string ...$tags): self
    {
        foreach ($tags as $tag) {
            if ($tag === '') {
                throw new InvalidConfiguration(\sprintf('Suite "%s" was given an empty tag.', $this->name));
            }

            $this->tags[] = $tag;
        }

        return $this;
    }

    /**
     * @internal
     *
     * @throws InvalidConfiguration when the suite was given no paths
     */
    public function toConfiguration(): SuiteConfiguration
    {
        if ($this->paths === []) {
            throw new InvalidConfiguration(\sprintf(
                'Suite "%s" has no paths. Call in() with at least one directory inside its configurator.',
                $this->name,
            ));
        }

        return new SuiteConfiguration($this->name, $this->paths, $this->tags);
    }
}
