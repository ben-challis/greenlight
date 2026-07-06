<?php

declare(strict_types=1);

namespace Greenlight\Core\Test;

use Greenlight\Core\Wire\InvalidWirePayload;
use Greenlight\Core\Wire\Wire;
use Greenlight\Core\Wire\WireSerializable;

/**
 * Everything discovery knows about a test method before execution, with
 * class-level attributes already merged in (method-level wins on conflict).
 */
final readonly class TestMetadata implements WireSerializable
{
    /**
     * @var list<non-empty-string>
     */
    public array $groups;

    /**
     * @param non-empty-string $class
     * @param non-empty-string $method
     * @param list<string> $groups
     * @param non-empty-string|null $skipReason
     * @param non-empty-string|null $skipUnlessCondition
     * @param positive-int|null $retryTimes
     * @param non-empty-string|null $retryOnlyOn
     * @param non-empty-string|null $dataSetProvider
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
    ) {
        $validated = [];

        foreach ($groups as $group) {
            if ($group === '') {
                throw new \InvalidArgumentException('Group names cannot be empty.');
            }

            $validated[] = $group;
        }

        $this->groups = $validated;
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
            'retryTimes' => $this->retryTimes,
            'retryOnlyOn' => $this->retryOnlyOn,
            'timeoutSeconds' => $this->timeoutSeconds,
            'isolated' => $this->isolated,
            'dataSetProvider' => $this->dataSetProvider,
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
        );
    }
}
