<?php

declare(strict_types=1);

namespace Tempest\Container;

interface Container
{
    public function get(string $className, string|\UnitEnum|null $tag = null, mixed ...$params): object;

    public function singleton(string $className, mixed $definition, string|\UnitEnum|null $tag = null): self;

    public function reset(): self;
}

final class GenericContainer implements Container
{
    public static function instance(): ?self {}

    public static function setInstance(?self $instance): void {}

    public function get(string $className, string|\UnitEnum|null $tag = null, mixed ...$params): object {}

    public function singleton(string $className, mixed $definition, string|\UnitEnum|null $tag = null): self {}

    public function reset(): self {}
}

namespace Tempest\Core;

use Tempest\Container\Container;

interface Kernel
{
    public const string VERSION = '3.18.0';

    public string $root { get; }

    public string $internalStorage { get; }

    public Container $container { get; }

    public function shutdown(int|string $status = ''): void;
}

final class FrameworkKernel implements Kernel
{
    public string $root;

    public string $internalStorage;

    public Container $container;

    /**
     * @param list<\Tempest\Discovery\DiscoveryLocation> $discoveryLocations
     */
    public static function boot(
        string $root,
        array $discoveryLocations = [],
        ?Container $container = null,
        ?string $internalStorage = null,
        bool $longRunning = false,
    ): self {}

    public function shutdown(int|string $status = ''): void {}
}

namespace Tempest\Discovery;

final readonly class DiscoveryLocation
{
    public function __construct(public string $namespace, public string $path) {}
}

namespace Tempest\Http;

interface Request {}

enum Method: string
{
    case GET = 'GET';
}

final class GenericRequest implements Request
{
    /**
     * @param array<array-key, mixed> $body
     * @param array<array-key, mixed> $headers
     * @param array<array-key, mixed> $files
     */
    public function __construct(
        public Method $method,
        public string $uri,
        public array $body = [],
        public array $headers = [],
        public array $files = [],
        public ?string $raw = null,
    ) {}
}
