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
            throw InvalidConfiguration::emptySuiteName();
        }

        if (!\array_is_list($paths)) {
            throw InvalidConfiguration::suitePathsNotAList($name);
        }

        $validatedPaths = [];

        foreach ($paths as $path) {
            if (!\is_string($path)) {
                throw InvalidConfiguration::suitePathNotAString($name);
            }

            if ($path === '') {
                throw InvalidConfiguration::emptySuitePath($name);
            }

            if (\str_contains($path, "\0")) {
                throw InvalidConfiguration::suitePathContainsNullByte($name);
            }

            $validatedPaths[] = $path;
        }

        if ($validatedPaths === []) {
            throw InvalidConfiguration::missingSuitePaths($name);
        }

        if (!\array_is_list($tags)) {
            throw InvalidConfiguration::suiteTagsNotAList($name);
        }

        $validatedTags = [];

        foreach ($tags as $tag) {
            if (!\is_string($tag)) {
                throw InvalidConfiguration::suiteTagNotAString($name);
            }

            if ($tag === '') {
                throw InvalidConfiguration::emptySuiteTag($name);
            }

            $validatedTags[] = $tag;
        }

        $this->name = $name;
        $this->paths = $validatedPaths;
        $this->tags = $validatedTags;
    }
}
