<?php

declare(strict_types=1);

namespace Greenlight\Core\Test;

use Greenlight\Core\Wire\InvalidWirePayload;
use Greenlight\Core\Wire\Wire;
use Greenlight\Core\Wire\WireSerializable;

/**
 * Everything discovery knows about a test method before execution.
 *
 * Class-level attributes are already merged in; method-level wins on
 * conflict.
 */
final readonly class TestMetadata implements WireSerializable
{
    /**
     * @var list<non-empty-string>
     */
    public array $groups;

    /**
     * @var list<scalar|null>
     */
    public array $skipUnlessArguments;

    /**
     * @param non-empty-string $class
     * @param non-empty-string $method
     * @param list<string> $groups
     * @param non-empty-string|null $skipReason
     * @param non-empty-string|null $skipUnlessCondition
     * @param positive-int|null $retryTimes
     * @param non-empty-string|null $retryOnlyOn
     * @param non-empty-string|null $dataSetProvider
     * @param list<mixed> $skipUnlessArguments validated to scalars or null
     *
     * @throws \InvalidArgumentException
     */
    public function __construct(
        public string $class,
        public string $method,
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
    ) {
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

            $validatedArguments[] = $argument;
        }

        $this->skipUnlessArguments = $validatedArguments;
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
            'capture' => $this->capture,
            'noExpectations' => $this->noExpectations,
        ];
    }

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
        $retryTimes = Wire::nullableInt($payload, 'retryTimes');
        $timeoutSeconds = Wire::nullableFloat($payload, 'timeoutSeconds');
        $skipUnlessArguments = self::skipUnlessArgumentsFromWire($payload);

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
        );
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

            $arguments[] = $argument;
        }

        return $arguments;
    }
}
