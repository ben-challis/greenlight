<?php

declare(strict_types=1);

namespace Greenlight\Execution\Plugin;

use Greenlight\Plugin\Plugin;
use Greenlight\Plugin\PluginDefinition;
use Greenlight\Plugin\RunAcceptancePolicy;
use Greenlight\Result\ResultSummary;
use Greenlight\Result\RunPolicy;

/**
 * Applies command-side run acceptance policies for one completed run.
 *
 * @internal
 */
final readonly class RunPolicyRuntime extends PluginRuntime
{
    /**
     * @param list<PluginDefinition> $definitions
     * @throws PluginRuntimeError
     */
    public static function fromDefinitions(array $definitions, RunPolicy $builtIn): self
    {
        $plugins = [new ConfiguredRunAcceptance($builtIn)];

        foreach ($definitions as $definition) {
            if (!$definition->supports(RunAcceptancePolicy::class)) {
                continue;
            }

            try {
                $plugins[] = $definition->create();
            } catch (\Throwable $failure) {
                throw PluginRuntimeError::creationFailed($definition->pluginClass, $failure);
            }
        }

        return new self($plugins);
    }

    /** @param list<Plugin> $plugins */
    public static function fromPlugins(array $plugins): self
    {
        return new self($plugins);
    }

    /** @param list<Plugin> $plugins */
    private function __construct(array $plugins)
    {
        parent::__construct($plugins);
    }

    /**
     * @param non-negative-int $retriedPasses
     * @return list<non-empty-string>
     * @throws PluginRuntimeError
     */
    public function failureMessages(ResultSummary $summary, int $retriedPasses): array
    {
        if (!$summary->isSuccessful()) {
            return [];
        }

        $messages = [];

        foreach ($this->ordered(RunAcceptancePolicy::class) as $policy) {
            try {
                $message = $policy->failureMessage($summary, $retriedPasses);
            } catch (\Throwable $failure) {
                throw PluginRuntimeError::hookFailed($policy::class, 'failureMessage', $failure);
            }

            if ($message === null) {
                continue;
            }

            $message = \trim($message);

            if ($message === '') {
                throw PluginRuntimeError::emptyRunPolicyFailure($policy::class);
            }

            $messages[] = $message;
        }

        return $messages;
    }
}
