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
use Greenlight\Sandbox\EnvironmentVariables;

final readonly class ConditionsTest
{
    public function __construct(private EnvironmentVariables $environment) {}

    /** @param non-empty-string $extension */
    #[Test]
    #[DataSet('extensionLoadedStates')]
    public function extensionLoadedChecksTheLoadedExtensionList(string $extension, bool $expected): void
    {
        Expect::that(new ExtensionLoaded($extension)->isSatisfied())
            ->because('the condition MUST match only a loaded extension')
            ->toBe($expected);
    }

    /**
     * @return iterable<string, array{non-empty-string, bool}>
     */
    public static function extensionLoadedStates(): iterable
    {
        yield 'loaded extension' => ['json', true];
        yield 'missing extension' => ['greenlight_no_such_extension', false];
    }

    /** @param non-empty-string $extension */
    #[Test]
    #[DataSet('extensionMissingStates')]
    public function extensionMissingIsTheInverseOfExtensionLoaded(string $extension, bool $expected): void
    {
        Expect::that(new ExtensionMissing($extension)->isSatisfied())
            ->because('the condition MUST match only an extension that is not loaded')
            ->toBe($expected);
    }

    /**
     * @return iterable<string, array{non-empty-string, bool}>
     */
    public static function extensionMissingStates(): iterable
    {
        yield 'loaded extension' => ['json', false];
        yield 'missing extension' => ['greenlight_no_such_extension', true];
    }

    #[Test]
    public function environmentVariableSetDetectsPresence(): void
    {
        $suffix = \strtoupper(\bin2hex(\random_bytes(6)));
        $presentName = 'GREENLIGHT_CONDITION_PRESENT_' . $suffix;
        $absentName = 'GREENLIGHT_CONDITION_ABSENT_' . $suffix;
        $this->environment->set($presentName, 'anything');
        $this->environment->unset($absentName);

        Expect::that(new EnvironmentVariableSet($presentName)->isSatisfied())
            ->because('the condition MUST match a set environment variable')
            ->toBeTrue();
        Expect::that(new EnvironmentVariableSet($absentName)->isSatisfied())
            ->because('the condition MUST reject an unset environment variable')
            ->toBeFalse();
    }

    #[Test]
    public function environmentVariableEqualsComparesTheExactValue(): void
    {
        $suffix = \strtoupper(\bin2hex(\random_bytes(6)));
        $presentName = 'GREENLIGHT_CONDITION_PRESENT_' . $suffix;
        $absentName = 'GREENLIGHT_CONDITION_ABSENT_' . $suffix;
        $this->environment->set($presentName, 'expected');
        $this->environment->unset($absentName);

        Expect::that(new EnvironmentVariableEquals($presentName, 'expected')->isSatisfied())
            ->because('the condition MUST match the exact environment variable value')
            ->toBeTrue();
        Expect::that(new EnvironmentVariableEquals($presentName, 'other')->isSatisfied())
            ->because('the condition MUST reject a different environment variable value')
            ->toBeFalse();
        Expect::that(new EnvironmentVariableEquals($absentName, 'expected')->isSatisfied())
            ->because('the condition MUST reject an unset environment variable')
            ->toBeFalse();
    }

    /** @param non-empty-string $family */
    #[Test]
    #[DataSet('operatingSystemFamilies')]
    public function operatingSystemFamilyComparesCaseInsensitively(string $family, bool $expected): void
    {
        Expect::that(new OperatingSystemFamily($family)->isSatisfied())
            ->because('the condition MUST compare operating system family names without case sensitivity')
            ->toBe($expected);
    }

    /**
     * @return iterable<string, array{non-empty-string, bool}>
     */
    public static function operatingSystemFamilies(): iterable
    {
        yield 'exact case' => [\PHP_OS_FAMILY, true];
        yield 'lowercase' => [\strtolower(\PHP_OS_FAMILY), true];
        yield 'unknown family' => ['NotAnOperatingSystem', false];
    }

    /** @param non-empty-string $version */
    #[Test]
    #[DataSet('minimumPhpVersions')]
    public function phpVersionAtLeastComparesAgainstTheRunningVersion(string $version, bool $expected): void
    {
        Expect::that(new PhpVersionAtLeast($version)->isSatisfied())
            ->because('the condition MUST accept versions that do not exceed the current PHP version')
            ->toBe($expected);
    }

    /**
     * @return iterable<string, array{non-empty-string, bool}>
     */
    public static function minimumPhpVersions(): iterable
    {
        yield 'older version' => ['8.0', true];
        yield 'current version' => [\PHP_VERSION, true];
        yield 'newer version' => ['99.0', false];
    }

    /** @param non-empty-string $version */
    #[Test]
    #[DataSet('maximumPhpVersions')]
    public function phpVersionLessThanComparesAgainstTheRunningVersion(string $version, bool $expected): void
    {
        Expect::that(new PhpVersionLessThan($version)->isSatisfied())
            ->because('the condition MUST accept versions that exceed the current PHP version')
            ->toBe($expected);
    }

    /**
     * @return iterable<string, array{non-empty-string, bool}>
     */
    public static function maximumPhpVersions(): iterable
    {
        yield 'newer version' => ['99.0', true];
        yield 'older version' => ['8.0', false];
        yield 'current version' => [\PHP_VERSION, false];
    }

    /** @param non-empty-string $function */
    #[Test]
    #[DataSet('functionAvailability')]
    public function functionAvailableChecksCallableFunctions(string $function, bool $expected): void
    {
        Expect::that(new FunctionAvailable($function)->isSatisfied())
            ->because('the condition MUST match only an available function')
            ->toBe($expected);
    }

    /**
     * @return iterable<string, array{non-empty-string, bool}>
     */
    public static function functionAvailability(): iterable
    {
        yield 'available function' => ['strlen', true];
        yield 'missing function' => ['greenlight_no_such_function', false];
    }

    /** @param non-empty-string $class */
    #[Test]
    #[DataSet('classAvailability')]
    public function classAvailableChecksAutoloadableClasses(string $class, bool $expected): void
    {
        Expect::that(new ClassAvailable($class)->isSatisfied())
            ->because('the condition MUST match only an autoloadable class')
            ->toBe($expected);
    }

    /**
     * @return iterable<string, array{non-empty-string, bool}>
     */
    public static function classAvailability(): iterable
    {
        yield 'autoloadable class' => [\stdClass::class, true];
        yield 'missing class' => ['Greenlight\NoSuchClassAnywhere', false];
    }

    #[Test]
    public function classAvailableRejectsAnEmptyClassName(): void
    {
        Expect::that(static fn(): ClassAvailable => new ClassAvailable('')) // @phpstan-ignore argument.type (deliberately invalid: tests runtime validation)
            ->because('a class availability condition MUST identify the class')
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'Class name cannot be empty.',
            );
    }

}
