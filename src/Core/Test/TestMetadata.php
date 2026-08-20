<?php

declare(strict_types=1);

namespace Greenlight\Core\Test;

use Greenlight\Core\Wire\InvalidWirePayload;
use Greenlight\Core\Wire\Wire;
use Greenlight\Core\Wire\WireSerializable;

/**
 * Contains class attributes and method attributes. A method attribute replaces
 * a class attribute when they conflict.
 */
final readonly class TestMetadata implements WireSerializable
{
    /**
     * @var non-empty-string
     */
    public string $class;

    /**
     * @var non-empty-string
     */
    public string $method;

    /**
     * @var list<non-empty-string>
     */
    public array $groups;

    /**
     * @var list<scalar|null>
     */
    public array $skipUnlessArguments;

    /**
     * @var list<non-empty-string>
     */
    public array $resources;

    /**
     * @param list<string> $groups
     * @param non-empty-string|null $skipReason
     * @param non-empty-string|null $skipUnlessCondition
     * @param positive-int|null $retryTimes
     * @param non-empty-string|null $retryOnlyOn
     * @param non-empty-string|null $dataSetProvider
     * @param list<mixed> $skipUnlessArguments validated to scalars or null
     * @param list<string> $resources named resources required by this test entry
     * @param non-empty-string|null $dataSetProviderClass
     *
     * @throws \InvalidArgumentException
     */
    public function __construct(
        string $class,
        string $method,
        array $groups = [],
        public ?string $skipReason = null,
        public ?string $skipUnlessCondition = null,
        public ?int $retryTimes = null,
        public ?string $retryOnlyOn = null,
        public ?float $timeoutSeconds = null,
        public bool $isolated = false,
        public ?string $dataSetProvider = null,
        public bool $capture = true,
        public bool $noExpectations = false,
        array $skipUnlessArguments = [],
        array $resources = [],
        public ?string $dataSetProviderClass = null,
    ) {
        if ($class === '') {
            throw new \InvalidArgumentException('Test metadata class must not be empty.');
        }

        if ($method === '') {
            throw new \InvalidArgumentException('Test metadata method must not be empty.');
        }

        $this->class = $class;
        $this->method = $method;

        if (!self::isValidTimeout($timeoutSeconds)) {
            throw new \InvalidArgumentException('Timeout seconds must be finite and greater than zero.');
        }

        $validated = [];

        foreach ($groups as $group) {
            if ($group === '') {
                throw new \InvalidArgumentException('Group names cannot be empty.');
            }

            $validated[] = $group;
        }

        $this->groups = $validated;

        $validatedArguments = [];

        foreach ($skipUnlessArguments as $argument) {
            if ($argument !== null && !\is_scalar($argument)) {
                throw new \InvalidArgumentException(\sprintf(
                    'Skip-unless arguments must be scalars or null, got %s.',
                    \get_debug_type($argument),
                ));
            }

            if (\is_float($argument) && !\is_finite($argument)) {
                throw new \InvalidArgumentException('Skip-unless arguments MUST use finite floats.');
            }

            $validatedArguments[] = $argument;
        }

        $this->skipUnlessArguments = $validatedArguments;

        $validatedResources = [];

        foreach ($resources as $resource) {
            ResourceName::assertValid($resource);
            $validatedResources[$resource] = $resource;
        }

        $this->resources = \array_values($validatedResources);
    }

    #[\Override]
    public function toWire(): array
    {
        return [
            'class' => $this->class,
            'method' => $this->method,
            'groups' => $this->groups,
            'skipReason' => $this->skipReason,
            'skipUnlessCondition' => $this->skipUnlessCondition,
            'skipUnlessArguments' => $this->skipUnlessArguments,
            'retryTimes' => $this->retryTimes,
            'retryOnlyOn' => $this->retryOnlyOn,
            'timeoutSeconds' => $this->timeoutSeconds,
            'isolated' => $this->isolated,
            'dataSetProvider' => $this->dataSetProvider,
            'dataSetProviderClass' => $this->dataSetProviderClass,
            'capture' => $this->capture,
            'noExpectations' => $this->noExpectations,
            'resources' => $this->resources,
        ];
    }

    /**
     * @throws \InvalidArgumentException when the decoded metadata violates a domain invariant
     * @throws InvalidWirePayload when a required field is missing or has the wrong type
     */
    #[\Override]
    public static function fromWire(array $payload): static
    {
        $groups = Wire::listOfStrings($payload, 'groups');

        foreach ($groups as $group) {
            if ($group === '') {
                throw InvalidWirePayload::wrongType('groups', 'a list of non-empty strings', $group);
            }
        }

        $skipReason = Wire::nullableString($payload, 'skipReason');
        $skipUnless = Wire::nullableString($payload, 'skipUnlessCondition');
        $retryOnlyOn = Wire::nullableString($payload, 'retryOnlyOn');
        $dataSetProvider = Wire::nullableString($payload, 'dataSetProvider');
        $dataSetProviderClass = \array_key_exists('dataSetProviderClass', $payload)
            ? Wire::nullableString($payload, 'dataSetProviderClass')
            : null;
        $retryTimes = Wire::nullableInt($payload, 'retryTimes');
        $timeoutSeconds = Wire::nullableFloat($payload, 'timeoutSeconds');

        if (!self::isValidTimeout($timeoutSeconds)) {
            throw InvalidWirePayload::wrongType(
                'timeoutSeconds',
                'a finite float greater than zero or null',
                $timeoutSeconds,
            );
        }

        $skipUnlessArguments = self::skipUnlessArgumentsFromWire($payload);
        $resources = \array_key_exists('resources', $payload) ? Wire::listOfStrings($payload, 'resources') : [];

        foreach ($resources as $resource) {
            if (!ResourceName::isValid($resource)) {
                throw InvalidWirePayload::wrongType('resources', 'a list of canonical resource names', $resource);
            }
        }

        return new self(
            Wire::nonEmptyString($payload, 'class'),
            Wire::nonEmptyString($payload, 'method'),
            $groups,
            $skipReason === '' ? null : $skipReason,
            $skipUnless === '' ? null : $skipUnless,
            $retryTimes === null ? null : \max(1, $retryTimes),
            $retryOnlyOn === '' ? null : $retryOnlyOn,
            $timeoutSeconds,
            Wire::bool($payload, 'isolated'),
            $dataSetProvider === '' ? null : $dataSetProvider,
            Wire::bool($payload, 'capture'),
            \array_key_exists('noExpectations', $payload) && Wire::bool($payload, 'noExpectations'),
            $skipUnlessArguments,
            $resources,
            $dataSetProviderClass === '' ? null : $dataSetProviderClass,
        );
    }

    private static function isValidTimeout(?float $seconds): bool
    {
        return $seconds === null || (\is_finite($seconds) && $seconds > 0.0);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<scalar|null>
     *
     * @throws InvalidWirePayload
     */
    private static function skipUnlessArgumentsFromWire(array $payload): array
    {
        if (!\array_key_exists('skipUnlessArguments', $payload)) {
            return [];
        }

        $value = $payload['skipUnlessArguments'];

        if (!\is_array($value) || !\array_is_list($value)) {
            throw InvalidWirePayload::wrongType('skipUnlessArguments', 'a list of scalars or nulls', $value);
        }

        $arguments = [];

        foreach ($value as $argument) {
            if ($argument !== null && !\is_scalar($argument)) {
                throw InvalidWirePayload::wrongType('skipUnlessArguments', 'a list of scalars or nulls', $argument);
            }

            if (\is_float($argument) && !\is_finite($argument)) {
                throw InvalidWirePayload::wrongType(
                    'skipUnlessArguments',
                    'a list of scalars or nulls with finite floats',
                    $argument,
                );
            }

            $arguments[] = $argument;
        }

        return $arguments;
    }
}
