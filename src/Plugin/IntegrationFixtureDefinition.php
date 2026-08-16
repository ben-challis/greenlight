<?php

declare(strict_types=1);

namespace Greenlight\Plugin;

/**
 * One orchestrator-owned integration fixture and its dependencies. IDs must
 * remain string keys in PHP maps.
 */
final readonly class IntegrationFixtureDefinition
{
    /**
     * @var non-empty-string
     */
    public string $id;

    /**
     * @var list<non-empty-string>
     */
    public array $dependsOn;

    /**
     * @param \Closure(IntegrationFixtureContext): void $provision
     * @param list<mixed> $dependsOn
     */
    public function __construct(
        string $id,
        public \Closure $provision,
        array $dependsOn = [],
    ) {
        if ($id === '' || \preg_match('//u', $id) !== 1) {
            throw new \InvalidArgumentException('Integration fixture IDs must be non-empty UTF-8 strings.');
        }

        if ($this->becomesIntegerKey($id)) {
            throw new \InvalidArgumentException('Integration fixture IDs must not use integer strings.');
        }

        $seen = [];
        $validatedDependencies = [];

        foreach ($dependsOn as $dependency) {
            if (!\is_string($dependency)
                || $dependency === ''
                || \preg_match('//u', $dependency) !== 1
                || $this->becomesIntegerKey($dependency)
            ) {
                throw new \InvalidArgumentException(\sprintf('Integration fixture "%s" has an invalid dependency ID.', $id));
            }

            if (isset($seen[$dependency])) {
                throw new \InvalidArgumentException(\sprintf(
                    'Integration fixture "%s" declares dependency "%s" more than once.',
                    $id,
                    $dependency,
                ));
            }

            $seen[$dependency] = true;
            $validatedDependencies[] = $dependency;
        }

        $this->id = $id;
        $this->dependsOn = $validatedDependencies;
    }

    private function becomesIntegerKey(string $id): bool
    {
        $integer = \filter_var($id, \FILTER_VALIDATE_INT);

        return \is_int($integer) && (string) $integer === $id;
    }
}
