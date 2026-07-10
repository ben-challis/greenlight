<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Condition;

use Greenlight\Attribute\Test;
use Greenlight\Condition\ClassAvailable;
use Greenlight\Condition\EnvironmentVariableEquals;
use Greenlight\Condition\EnvironmentVariableSet;
use Greenlight\Condition\ExtensionLoaded;
use Greenlight\Condition\ExtensionMissing;
use Greenlight\Condition\FunctionAvailable;
use Greenlight\Condition\OperatingSystemFamily;
use Greenlight\Condition\PhpVersionAtLeast;
use Greenlight\Condition\PhpVersionLessThan;
use Greenlight\Expect\Expect;

final class ConditionsTest
{
    #[Test]
    public function extensionLoadedChecksTheLoadedExtensionList(): void
    {
        Expect::that(new ExtensionLoaded('json')->isSatisfied())->toBeTrue()
            ->and(new ExtensionLoaded('greenlight_no_such_extension')->isSatisfied())->toBeFalse();
    }

    #[Test]
    public function extensionMissingIsTheInverseOfExtensionLoaded(): void
    {
        Expect::that(new ExtensionMissing('json')->isSatisfied())->toBeFalse()
            ->and(new ExtensionMissing('greenlight_no_such_extension')->isSatisfied())->toBeTrue();
    }

    #[Test]
    public function environmentVariableSetDetectsPresence(): void
    {
        \putenv('GREENLIGHT_CONDITION_PROBE=anything');

        try {
            Expect::that(new EnvironmentVariableSet('GREENLIGHT_CONDITION_PROBE')->isSatisfied())->toBeTrue()
                ->and(new EnvironmentVariableSet('GREENLIGHT_CONDITION_ABSENT')->isSatisfied())->toBeFalse();
        } finally {
            \putenv('GREENLIGHT_CONDITION_PROBE');
        }
    }

    #[Test]
    public function environmentVariableEqualsComparesTheExactValue(): void
    {
        \putenv('GREENLIGHT_CONDITION_PROBE=expected');

        try {
            Expect::that(new EnvironmentVariableEquals('GREENLIGHT_CONDITION_PROBE', 'expected')->isSatisfied())->toBeTrue()
                ->and(new EnvironmentVariableEquals('GREENLIGHT_CONDITION_PROBE', 'other')->isSatisfied())->toBeFalse()
                ->and(new EnvironmentVariableEquals('GREENLIGHT_CONDITION_ABSENT', 'expected')->isSatisfied())->toBeFalse();
        } finally {
            \putenv('GREENLIGHT_CONDITION_PROBE');
        }
    }

    #[Test]
    public function operatingSystemFamilyComparesCaseInsensitively(): void
    {
        Expect::that(new OperatingSystemFamily(\PHP_OS_FAMILY)->isSatisfied())->toBeTrue()
            ->and(new OperatingSystemFamily(\strtolower(\PHP_OS_FAMILY))->isSatisfied())->toBeTrue()
            ->and(new OperatingSystemFamily('NotAnOperatingSystem')->isSatisfied())->toBeFalse();
    }

    #[Test]
    public function phpVersionAtLeastComparesAgainstTheRunningVersion(): void
    {
        Expect::that(new PhpVersionAtLeast('8.0')->isSatisfied())->toBeTrue()
            ->and(new PhpVersionAtLeast(\PHP_VERSION)->isSatisfied())->toBeTrue()
            ->and(new PhpVersionAtLeast('99.0')->isSatisfied())->toBeFalse();
    }

    #[Test]
    public function phpVersionLessThanComparesAgainstTheRunningVersion(): void
    {
        Expect::that(new PhpVersionLessThan('99.0')->isSatisfied())->toBeTrue()
            ->and(new PhpVersionLessThan('8.0')->isSatisfied())->toBeFalse()
            ->and(new PhpVersionLessThan(\PHP_VERSION)->isSatisfied())->toBeFalse();
    }

    #[Test]
    public function functionAvailableChecksCallableFunctions(): void
    {
        Expect::that(new FunctionAvailable('strlen')->isSatisfied())->toBeTrue()
            ->and(new FunctionAvailable('greenlight_no_such_function')->isSatisfied())->toBeFalse();
    }

    #[Test]
    public function classAvailableChecksAutoloadableClasses(): void
    {
        Expect::that(new ClassAvailable(\stdClass::class)->isSatisfied())->toBeTrue()
            ->and(new ClassAvailable('Greenlight\NoSuchClassAnywhere')->isSatisfied())->toBeFalse();
    }
}
