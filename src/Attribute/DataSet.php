<?php

declare(strict_types=1);

namespace Greenlight\Attribute;

/**
 * References a pure public static data provider. The provider runs during
 * discovery.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final readonly class DataSet
{
    /**
     * @var non-empty-string
     */
    public string $provider;

    /**
     * @var non-empty-string|null
     */
    public ?string $providerClass;

    /**
     * With one argument, `$provider` names a method on the test class. With two
     * arguments, it names the provider class and `$method` names the provider
     * method.
     *
     * @param non-empty-string $provider
     * @param non-empty-string|null $method
     */
    public function __construct(string $provider, ?string $method = null)
    {
        $this->provider = $method ?? $provider;
        $this->providerClass = $method === null ? null : $provider;
    }
}
