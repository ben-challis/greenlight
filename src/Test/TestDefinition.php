<?php

declare(strict_types=1);

namespace Greenlight\Test;

use Greenlight\Wire\InvalidWirePayload;
use Greenlight\Wire\Wire;
use Greenlight\Wire\WireCommunicationFailed;
use Greenlight\Wire\WireSerializable;

/**
 * Contains one test declaration and its discovery policies.
 */
final readonly class TestDefinition implements WireSerializable
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
     * @param list<string> $groups
     *
     * @throws \InvalidArgumentException
     */
    public function __construct(
        string $class,
        string $method,
        array $groups = [],
        public SkipPolicy $skip = new SkipPolicy(),
        public RetryPolicy $retry = new RetryPolicy(),
        public DataProvider $dataProvider = new DataProvider(),
        public ExecutionPolicy $execution = new ExecutionPolicy(),
        public SchedulingPolicy $scheduling = new SchedulingPolicy(),
    ) {
        if ($class === '') {
            throw new \InvalidArgumentException('Test definition class must not be empty.');
        }

        if ($method === '') {
            throw new \InvalidArgumentException('Test definition method must not be empty.');
        }

        $this->class = $class;
        $this->method = $method;
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
            'skip' => $this->skip->toWire(),
            'retry' => $this->retry->toWire(),
            'dataProvider' => $this->dataProvider->toWire(),
            'execution' => $this->execution->toWire(),
            'scheduling' => $this->scheduling->toWire(),
        ];
    }

    /** @throws WireCommunicationFailed */
    #[\Override]
    public static function fromWire(array $payload): static
    {
        $groups = Wire::listOfStrings($payload, 'groups');

        foreach ($groups as $group) {
            if ($group === '') {
                throw InvalidWirePayload::wrongType('groups', 'a list of non-empty strings', $group);
            }
        }

        return new self(
            Wire::nonEmptyString($payload, 'class'),
            Wire::nonEmptyString($payload, 'method'),
            $groups,
            SkipPolicy::fromWire(Wire::map($payload, 'skip')),
            RetryPolicy::fromWire(Wire::map($payload, 'retry')),
            DataProvider::fromWire(Wire::map($payload, 'dataProvider')),
            ExecutionPolicy::fromWire(Wire::map($payload, 'execution')),
            SchedulingPolicy::fromWire(Wire::map($payload, 'scheduling')),
        );
    }
}
