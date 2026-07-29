<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner\Worker;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Runner\Worker\ClassContext;
use Greenlight\Tests\Fixture\Runner\Worker\CachedDataSetProbe;
use Greenlight\Tests\Fixture\Runner\Worker\ClassContextDataProbe;

final class ClassContextTest
{
    #[Test]
    public function anUnloadedTestClassIsRejected(): void
    {
        Expect::that(static fn(): ClassContext => ClassContext::for('Missing\ExampleTest'))
            ->because('the worker cannot execute a class that is absent from its process')
            ->toThrow(
                \RuntimeException::class,
                message: 'This process cannot load test class "Missing\ExampleTest" from the execution plan.',
            );
    }

    #[Test]
    public function aDataSetRemovedAfterDiscoveryIsRejected(): void
    {
        $context = ClassContext::for(ClassContextDataProbe::class);

        Expect::that(static fn(): array => $context->argumentsFor('scalarRows', null, 'accepts', 'removed'))
            ->because('the worker rejects an execution-plan data set that no longer exists')
            ->toThrow(
                \RuntimeException::class,
                message: \sprintf(
                    'The execution plan contains data set "removed" for "%s::accepts()", '
                    . 'but its data provider no longer returns it. Run discovery again.',
                    ClassContextDataProbe::class,
                ),
            );
    }

    #[Test]
    public function aProviderRowMustBeAnArgumentArray(): void
    {
        $context = ClassContext::for(ClassContextDataProbe::class);

        Expect::that(static fn(): array => $context->argumentsFor('scalarRows', null, 'accepts', 'bad'))
            ->because('the worker requires each data set to contain positional arguments')
            ->toThrow(
                \RuntimeException::class,
                message: \sprintf(
                    'Data set "bad" of "%s::accepts()" requires an argument array. Actual type: string.',
                    ClassContextDataProbe::class,
                ),
            );
    }

    #[Test]
    public function dataProvidersRunOncePerClassContext(): void
    {
        CachedDataSetProbe::$providerCalls = 0;
        $context = ClassContext::for(CachedDataSetProbe::class);

        $first = $context->argumentsFor('rows', null, 'accepts', 'first');
        $second = $context->argumentsFor('rows', null, 'accepts', 'second');

        Expect::that($first)
            ->because('the class context MUST return the first cached data row')
            ->toBe(['alpha'])
            ->and($second)
            ->because('the class context MUST return another row from the same cache')
            ->toBe(['beta'])
            ->and(CachedDataSetProbe::$providerCalls)
            ->because('the class context MUST evaluate its data provider only once')
            ->toBe(1);
    }
}
