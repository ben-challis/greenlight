<?php

declare(strict_types=1);

namespace Greenlight\Config;

/**
 * Contains unresolved storage directories from the project configuration.
 * A null value uses the storage root or the system temporary directory.
 *
 * @internal
 */
final readonly class StorageConfiguration
{
    public function __construct(
        public ?string $rootDirectory = null,
        public ?string $stateDirectory = null,
        public ?string $cacheDirectory = null,
        public ?string $generatedCodeDirectory = null,
        public ?string $temporaryDirectory = null,
    ) {}
}
