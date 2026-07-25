<?php

declare(strict_types=1);

namespace Greenlight\Plugin;

/**
 * One orchestrator-owned integration fixture and its dependencies.
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
     * @param list<string> $dependsOn
     */
    public function __construct(
        string $id,
        public \Closure $provision,
        array $dependsOn = [],
    ) {
        if ($id === '' || \preg_match('//u', $id) !== 1) {
            throw new \InvalidArgumentException('Integration fixture IDs must be non-empty UTF-8 strings.');
        }

        $seen = [];

        foreach ($dependsOn as $dependency) {
            if ($dependency === '' || \preg_match('//u', $dependency) !== 1) {
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
        }

        $this->id = $id;
        $this->dependsOn = $dependsOn;
    }
}
