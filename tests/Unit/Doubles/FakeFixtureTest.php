<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Doubles;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Doubles\Fake;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Fixture\DiscoveryAttributes\AlwaysFalse;
use Greenlight\Tests\Fixture\DiscoveryAttributes\AlwaysTrue;
use Greenlight\Tests\Fixture\Expect\EvenNumbersExtension as ExpectEvenNumbersExtension;
use Greenlight\Tests\Fixture\Expect\PositiveNumbersExtension;
use Greenlight\Tests\Fixture\Laravel\ThrowingKernel;
use Greenlight\Tests\Fixture\Lifecycle\DisposeFails\FailingDisposalProbe;
use Greenlight\Tests\Fixture\Lifecycle\Services\ServiceProbe;
use Greenlight\Tests\Fixture\Lifecycle\Skips\AlwaysCondition;
use Greenlight\Tests\Fixture\Lifecycle\Skips\NeverCondition;
use Greenlight\Tests\Fixture\Lifecycle\VerifyOnDispose\VerifyingProbe;
use Greenlight\Tests\Fixture\PhpStanExtension\DigestExtension;
use Greenlight\Tests\Fixture\PhpStanExtensionConflict\ConflictingDigestExtension;
use Greenlight\Tests\Fixture\Plugins\EvenNumbersExtension as PluginEvenNumbersExtension;
use Greenlight\Tests\Fixture\Plugins\ProbeProvider;
use Greenlight\Tests\Fixture\Plugins\QuarantinePlugin;
use Greenlight\Tests\Fixture\Symfony\BareKernel;

final class FakeFixtureTest
{
    /**
     * @param class-string $fixture
     */
    #[Test]
    #[DataSet('manualInMemoryFixtures')]
    public function manualInMemoryFixturesIdentifyThemselvesAsFakes(string $fixture): void
    {
        Expect::that(\is_a($fixture, Fake::class, true))
            ->because('a manual in-memory fixture MUST identify itself as a fake')
            ->toBeTrue();
    }

    /**
     * @return iterable<string, array{class-string}>
     */
    public static function manualInMemoryFixtures(): iterable
    {
        yield AlwaysFalse::class => [AlwaysFalse::class];
        yield AlwaysTrue::class => [AlwaysTrue::class];
        yield ExpectEvenNumbersExtension::class => [ExpectEvenNumbersExtension::class];
        yield PositiveNumbersExtension::class => [PositiveNumbersExtension::class];
        yield ThrowingKernel::class => [ThrowingKernel::class];
        yield FailingDisposalProbe::class => [FailingDisposalProbe::class];
        yield ServiceProbe::class => [ServiceProbe::class];
        yield AlwaysCondition::class => [AlwaysCondition::class];
        yield NeverCondition::class => [NeverCondition::class];
        yield VerifyingProbe::class => [VerifyingProbe::class];
        yield DigestExtension::class => [DigestExtension::class];
        yield ConflictingDigestExtension::class => [ConflictingDigestExtension::class];
        yield PluginEvenNumbersExtension::class => [PluginEvenNumbersExtension::class];
        yield ProbeProvider::class => [ProbeProvider::class];
        yield QuarantinePlugin::class => [QuarantinePlugin::class];
        yield BareKernel::class => [BareKernel::class];
    }
}
