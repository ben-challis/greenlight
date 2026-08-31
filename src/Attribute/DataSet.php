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
     * @var class-string|null
     */
    public ?string $providerClass;

    /**
     * With one argument, `$provider` names a method on the test class. With two
     * arguments, it names the provider class and `$method` names the provider
     * method.
     *
     * @throws \InvalidArgumentException
     */
    public function __construct(string $provider, ?string $method = null)
    {
        if ($provider === '') {
            throw new \InvalidArgumentException(
                $method === null
                    ? 'Data set provider MUST NOT be empty.'
                    : 'Data set provider class MUST NOT be empty.',
            );
        }

        if ($method === '') {
            throw new \InvalidArgumentException('Data set provider method MUST NOT be empty.');
        }

        $this->provider = $method ?? $provider;

        /** @var class-string|null $providerClass */
        $providerClass = $method === null ? null : $provider;
        $this->providerClass = $providerClass;
    }
}
