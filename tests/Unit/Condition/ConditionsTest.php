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
use Greenlight\Fixture\EnvironmentSandbox;

final readonly class ConditionsTest
{
    public function __construct(private EnvironmentSandbox $environment) {}

    #[Test]
    public function extensionLoadedChecksTheLoadedExtensionList(): void
    {
        Expect::that(new ExtensionLoaded('json')->isSatisfied())
            ->because('extension loaded checks the loaded extension list')
            ->toBeTrue();
        Expect::that(new ExtensionLoaded('greenlight_no_such_extension')->isSatisfied())
            ->because('extension loaded checks the loaded extension list')
            ->toBeFalse();
    }

    #[Test]
    public function extensionMissingIsTheInverseOfExtensionLoaded(): void
    {
        Expect::that(new ExtensionMissing('json')->isSatisfied())
            ->because('extension missing is the inverse of extension loaded')
            ->toBeFalse();
        Expect::that(new ExtensionMissing('greenlight_no_such_extension')->isSatisfied())
            ->because('extension missing is the inverse of extension loaded')
            ->toBeTrue();
    }

    #[Test]
    public function environmentVariableSetDetectsPresence(): void
    {
        $suffix = \strtoupper(\bin2hex(\random_bytes(6)));
        $presentName = 'GREENLIGHT_CONDITION_PRESENT_' . $suffix;
        $absentName = 'GREENLIGHT_CONDITION_ABSENT_' . $suffix;
        $this->environment->set($presentName, 'anything');
        $this->environment->unset($absentName);

        Expect::that(new EnvironmentVariableSet($presentName)->isSatisfied())->toBeTrue();
        Expect::that(new EnvironmentVariableSet($absentName)->isSatisfied())->toBeFalse();
    }

    #[Test]
    public function environmentVariableEqualsComparesTheExactValue(): void
    {
        $suffix = \strtoupper(\bin2hex(\random_bytes(6)));
        $presentName = 'GREENLIGHT_CONDITION_PRESENT_' . $suffix;
        $absentName = 'GREENLIGHT_CONDITION_ABSENT_' . $suffix;
        $this->environment->set($presentName, 'expected');
        $this->environment->unset($absentName);

        Expect::that(new EnvironmentVariableEquals($presentName, 'expected')->isSatisfied())->toBeTrue();
        Expect::that(new EnvironmentVariableEquals($presentName, 'other')->isSatisfied())->toBeFalse();
        Expect::that(new EnvironmentVariableEquals($absentName, 'expected')->isSatisfied())->toBeFalse();
    }

    #[Test]
    public function operatingSystemFamilyComparesCaseInsensitively(): void
    {
        Expect::that(new OperatingSystemFamily(\PHP_OS_FAMILY)->isSatisfied())
            ->because('operating system family compares case insensitively')
            ->toBeTrue();
        Expect::that(new OperatingSystemFamily(\strtolower(\PHP_OS_FAMILY))->isSatisfied())
            ->because('operating system family compares case insensitively')
            ->toBeTrue();
        Expect::that(new OperatingSystemFamily('NotAnOperatingSystem')->isSatisfied())
            ->because('operating system family compares case insensitively')
            ->toBeFalse();
    }

    #[Test]
    public function phpVersionAtLeastComparesAgainstTheRunningVersion(): void
    {
        Expect::that(new PhpVersionAtLeast('8.0')->isSatisfied())
            ->because('PHP version at least compares against the current PHP version')
            ->toBeTrue();
        Expect::that(new PhpVersionAtLeast(\PHP_VERSION)->isSatisfied())
            ->because('PHP version at least compares against the current PHP version')
            ->toBeTrue();
        Expect::that(new PhpVersionAtLeast('99.0')->isSatisfied())
            ->because('PHP version at least compares against the current PHP version')
            ->toBeFalse();
    }

    #[Test]
    public function phpVersionLessThanComparesAgainstTheRunningVersion(): void
    {
        Expect::that(new PhpVersionLessThan('99.0')->isSatisfied())
            ->because('PHP version less than compares against the current PHP version')
            ->toBeTrue();
        Expect::that(new PhpVersionLessThan('8.0')->isSatisfied())
            ->because('PHP version less than compares against the current PHP version')
            ->toBeFalse();
        Expect::that(new PhpVersionLessThan(\PHP_VERSION)->isSatisfied())
            ->because('PHP version less than compares against the current PHP version')
            ->toBeFalse();
    }

    #[Test]
    public function functionAvailableChecksCallableFunctions(): void
    {
        Expect::that(new FunctionAvailable('strlen')->isSatisfied())
            ->because('function available checks callable functions')
            ->toBeTrue();
        Expect::that(new FunctionAvailable('greenlight_no_such_function')->isSatisfied())
            ->because('function available checks callable functions')
            ->toBeFalse();
    }

    #[Test]
    public function classAvailableChecksAutoloadableClasses(): void
    {
        Expect::that(new ClassAvailable(\stdClass::class)->isSatisfied())
            ->because('class available checks autoloadable classes')
            ->toBeTrue();
        Expect::that(new ClassAvailable('Greenlight\NoSuchClassAnywhere')->isSatisfied())
            ->because('class available checks autoloadable classes')
            ->toBeFalse();
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
