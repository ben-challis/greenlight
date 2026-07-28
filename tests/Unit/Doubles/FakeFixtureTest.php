<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Doubles;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\SkipUnless;
use Greenlight\Attribute\Test;
use Greenlight\Condition\ClassAvailable;
use Greenlight\Doubles\Fake;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Fixture\DiscoveryAttributes\AlwaysFalse;
use Greenlight\Tests\Fixture\DiscoveryAttributes\AlwaysTrue;
use Greenlight\Tests\Fixture\Expect\EvenNumbersExtension as ExpectEvenNumbersExtension;
use Greenlight\Tests\Fixture\Expect\PositiveNumbersExtension;
use Greenlight\Tests\Fixture\Laravel\ThrowingKernel;
use Greenlight\Tests\Fixture\Lifecycle\DisposeFails\FailingDisposalProbe;
use Greenlight\Tests\Fixture\Lifecycle\Skips\AlwaysCondition;
use Greenlight\Tests\Fixture\Lifecycle\Skips\NeverCondition;
use Greenlight\Tests\Fixture\Lifecycle\VerifyOnDispose\VerifyingProbe;
use Greenlight\Tests\Fixture\PhpStanExtension\DigestExtension;
use Greenlight\Tests\Fixture\PhpStanExtensionConflict\ConflictingDigestExtension;
use Greenlight\Tests\Fixture\Plugins\EvenNumbersExtension as PluginEvenNumbersExtension;
use Greenlight\Tests\Fixture\Plugins\ProbeProvider;
use Greenlight\Tests\Fixture\Plugins\QuarantinePlugin;
use Greenlight\Tests\Fixture\Symfony\BareKernel;
use Greenlight\Tests\Unit\Reporting\RecordingReporter;
use Greenlight\Tests\Unit\Reporting\RecordingTickingReporter;
use Illuminate\Foundation\Application as LaravelApplication;

final class FakeFixtureTest
{
    /**
     * @param class-string $fixture
     */
    #[Test]
    #[DataSet('manualInMemoryFixtures')]
    public function manualInMemoryFixturesIdentifyThemselvesAsFakes(string $fixture): void
    {
        Expect::that(\is_subclass_of($fixture, Fake::class))
            ->because('a manual in-memory fixture MUST identify itself as a fake')
            ->toBeTrue();
    }

    #[Test]
    #[SkipUnless(ClassAvailable::class, LaravelApplication::class)]
    #[DataSet('optionalLaravelFixtures')]
    public function optionalLaravelFixturesIdentifyThemselvesAsFakes(string $fixture): void
    {
        Expect::that(\is_subclass_of($fixture, Fake::class))
            ->because('the optional Laravel fixture MUST identify itself as a fake')
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
        yield FailingDisposalProbe::class => [FailingDisposalProbe::class];
        yield AlwaysCondition::class => [AlwaysCondition::class];
        yield NeverCondition::class => [NeverCondition::class];
        yield VerifyingProbe::class => [VerifyingProbe::class];
        yield DigestExtension::class => [DigestExtension::class];
        yield ConflictingDigestExtension::class => [ConflictingDigestExtension::class];
        yield PluginEvenNumbersExtension::class => [PluginEvenNumbersExtension::class];
        yield ProbeProvider::class => [ProbeProvider::class];
        yield QuarantinePlugin::class => [QuarantinePlugin::class];
        yield BareKernel::class => [BareKernel::class];
        yield RecordingReporter::class => [RecordingReporter::class];
        yield RecordingTickingReporter::class => [RecordingTickingReporter::class];
    }

    /**
     * @return iterable<string, array{class-string}>
     */
    public static function optionalLaravelFixtures(): iterable
    {
        yield ThrowingKernel::class => [ThrowingKernel::class];
    }
}
