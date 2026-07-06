<?php

declare(strict_types=1);

namespace Greenlight\Core\Test;

use Greenlight\Core\Wire\Wire;
use Greenlight\Core\Wire\WireSerializable;

/**
 * Everything discovery knows about a test method before execution, with
 * class-level attributes already merged in (method-level wins on conflict).
 */
final readonly class TestMetadata implements WireSerializable
{
    /**
     * @param non-empty-string $class
     * @param non-empty-string $method
     * @param list<non-empty-string> $groups
     * @param non-empty-string|null $skipReason
     * @param non-empty-string|null $skipUnlessCondition
     * @param positive-int|null $retryTimes
     * @param non-empty-string|null $retryOnlyOn
     * @param non-empty-string|null $dataSetProvider
     */
    public function __construct(
        public string $class,
        public string $method,
        public array $groups = [],
        public ?string $skipReason = null,
        public ?string $skipUnlessCondition = null,
        public ?int $retryTimes = null,
        public ?string $retryOnlyOn = null,
        public ?float $timeoutSeconds = null,
        public bool $isolated = false,
        public ?string $dataSetProvider = null,
    ) {}

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
        $groups = [];

        foreach (Wire::listOfStrings($payload, 'groups') as $group) {
            if ($group === '') {
                continue;
            }

            $groups[] = $group;
        }

        $skipReason = Wire::nullableString($payload, 'skipReason');
        $skipUnless = Wire::nullableString($payload, 'skipUnlessCondition');
        $retryOnlyOn = Wire::nullableString($payload, 'retryOnlyOn');
        $dataSetProvider = Wire::nullableString($payload, 'dataSetProvider');
        $retryTimes = $payload['retryTimes'] ?? null;
        $timeoutSeconds = $payload['timeoutSeconds'] ?? null;

        return new self(
            Wire::nonEmptyString($payload, 'class'),
            Wire::nonEmptyString($payload, 'method'),
            $groups,
            $skipReason === '' ? null : $skipReason,
            $skipUnless === '' ? null : $skipUnless,
            $retryTimes === null ? null : \max(1, Wire::int($payload, 'retryTimes')),
            $retryOnlyOn === '' ? null : $retryOnlyOn,
            $timeoutSeconds === null ? null : Wire::float($payload, 'timeoutSeconds'),
            Wire::bool($payload, 'isolated'),
            $dataSetProvider === '' ? null : $dataSetProvider,
        );
    }
}
