<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Condition;

use Greenlight\Attribute\DataSet;
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
        Expect::that(new ExtensionLoaded('json')->isSatisfied())->because('extension loaded checks the loaded extension list')->toBeTrue()
            ->and(new ExtensionLoaded('greenlight_no_such_extension')->isSatisfied())->toBeFalse();
    }

    #[Test]
    public function extensionMissingIsTheInverseOfExtensionLoaded(): void
    {
        Expect::that(new ExtensionMissing('json')->isSatisfied())->because('extension missing is the inverse of extension loaded')->toBeFalse()
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
        Expect::that(new OperatingSystemFamily(\PHP_OS_FAMILY)->isSatisfied())->because('operating system family compares case insensitively')->toBeTrue()
            ->and(new OperatingSystemFamily(\strtolower(\PHP_OS_FAMILY))->isSatisfied())->toBeTrue()
            ->and(new OperatingSystemFamily('NotAnOperatingSystem')->isSatisfied())->toBeFalse();
    }

    #[Test]
    public function phpVersionAtLeastComparesAgainstTheRunningVersion(): void
    {
        Expect::that(new PhpVersionAtLeast('8.0')->isSatisfied())->because('PHP version at least compares against the current PHP version')->toBeTrue()
            ->and(new PhpVersionAtLeast(\PHP_VERSION)->isSatisfied())->toBeTrue()
            ->and(new PhpVersionAtLeast('99.0')->isSatisfied())->toBeFalse();
    }

    #[Test]
    public function phpVersionLessThanComparesAgainstTheRunningVersion(): void
    {
        Expect::that(new PhpVersionLessThan('99.0')->isSatisfied())->because('PHP version less than compares against the current PHP version')->toBeTrue()
            ->and(new PhpVersionLessThan('8.0')->isSatisfied())->toBeFalse()
            ->and(new PhpVersionLessThan(\PHP_VERSION)->isSatisfied())->toBeFalse();
    }

    #[Test]
    public function functionAvailableChecksCallableFunctions(): void
    {
        Expect::that(new FunctionAvailable('strlen')->isSatisfied())->because('function available checks callable functions')->toBeTrue()
            ->and(new FunctionAvailable('greenlight_no_such_function')->isSatisfied())->toBeFalse();
    }

    #[Test]
    public function classAvailableChecksAutoloadableClasses(): void
    {
        Expect::that(new ClassAvailable(\stdClass::class)->isSatisfied())->because('class available checks autoloadable classes')->toBeTrue()
            ->and(new ClassAvailable('Greenlight\NoSuchClassAnywhere')->isSatisfied())->toBeFalse();
    }

    #[Test]
    public function classAvailableRejectsAnEmptyClassName(): void
    {
        Expect::that(static fn(): ClassAvailable => new ClassAvailable(''))
            ->because('a class availability condition MUST identify the class')
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'Class name MUST NOT be empty.',
            );
    }

    /**
     * @param class-string<PhpVersionAtLeast|PhpVersionLessThan> $condition
     */
    #[Test]
    #[DataSet('phpVersionConditions')]
    public function phpVersionConditionsRejectAnEmptyVersion(string $condition): void
    {
        Expect::that(static fn(): object => new $condition(''))
            ->because('a PHP version condition MUST identify a version')
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'PHP version MUST NOT be empty.',
            );
    }

    /**
     * @return iterable<string, array{class-string<PhpVersionAtLeast|PhpVersionLessThan>}>
     */
    public static function phpVersionConditions(): iterable
    {
        yield 'at least' => [PhpVersionAtLeast::class];
        yield 'less than' => [PhpVersionLessThan::class];
    }
}
