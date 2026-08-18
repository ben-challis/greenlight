<?php

declare(strict_types=1);

namespace Greenlight\Config;

/** @internal */
final readonly class SuiteConfiguration
{
    /**
     * @var non-empty-string
     */
    public string $name;

    /**
     * @var non-empty-list<non-empty-string>
     */
    public array $paths;

    /**
     * @var list<non-empty-string>
     */
    public array $tags;

    /**
     * @param array<mixed> $paths
     * @param array<mixed> $tags
     *
     * @throws InvalidConfiguration
     */
    public function __construct(string $name, array $paths, array $tags)
    {
        if ($name === '') {
            throw new InvalidConfiguration('Suite names cannot be empty.');
        }

        if (!\array_is_list($paths)) {
            throw new InvalidConfiguration(\sprintf('Suite "%s" paths must be a list.', $name));
        }

        $validatedPaths = [];

        foreach ($paths as $path) {
            if (!\is_string($path)) {
                throw new InvalidConfiguration(\sprintf(
                    'Suite "%s" was given a path that is not a string.',
                    $name,
                ));
            }

            if ($path === '') {
                throw new InvalidConfiguration(\sprintf('Suite "%s" was given an empty path.', $name));
            }

            if (\str_contains($path, "\0")) {
                throw new InvalidConfiguration(\sprintf('Suite "%s" paths cannot contain a null byte.', $name));
            }

            $validatedPaths[] = $path;
        }

        if ($validatedPaths === []) {
            throw new InvalidConfiguration(\sprintf(
                'Suite "%s" has no paths. Call in() with at least one directory inside its configurator.',
                $name,
            ));
        }

        if (!\array_is_list($tags)) {
            throw new InvalidConfiguration(\sprintf('Suite "%s" tags must be a list.', $name));
        }

        $validatedTags = [];

        foreach ($tags as $tag) {
            if (!\is_string($tag)) {
                throw new InvalidConfiguration(\sprintf(
                    'Suite "%s" was given a tag that is not a string.',
                    $name,
                ));
            }

            if ($tag === '') {
                throw new InvalidConfiguration(\sprintf('Suite "%s" was given an empty tag.', $name));
            }

            $validatedTags[] = $tag;
        }

        $this->name = $name;
        $this->paths = $validatedPaths;
        $this->tags = $validatedTags;
    }
}
