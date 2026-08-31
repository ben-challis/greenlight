<?php

declare(strict_types=1);

namespace Greenlight\Rector;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Group;
use Greenlight\Attribute\Isolated;
use Greenlight\Attribute\NoExpectations;
use Greenlight\Condition\ExtensionLoaded;
use Greenlight\Condition\OperatingSystemFamily;

/**
 * Classifies PHPUnit attributes for conversion. The converter renames an
 * attribute with its arguments, rewrites it into a Greenlight shape, or
 * drops it as inert metadata. An attribute that is absent from every list
 * rejects the class, because it changes behavior that Greenlight cannot
 * replicate.
 *
 * @internal
 */
final class PhpUnitAttributes
{
    public const string NAMESPACE_PREFIX = 'PHPUnit\Framework\Attributes\\';

    /**
     * Renames that keep the original arguments.
     *
     * @var array<string, class-string>
     */
    public const array RENAMES = [
        'DataProvider' => DataSet::class,
        'Group' => Group::class,
        'Ticket' => Group::class,
        'RunInSeparateProcess' => Isolated::class,
        'RunTestsInSeparateProcesses' => Isolated::class,
        'DoesNotPerformAssertions' => NoExpectations::class,
    ];

    /**
     * Test-size attributes that become named groups. This matches how
     * PHPUnit resolves them for selection.
     *
     * @var array<string, non-empty-string>
     */
    public const array SIZE_GROUPS = [
        'Small' => 'small',
        'Medium' => 'medium',
        'Large' => 'large',
    ];

    /**
     * Requirement attributes that become a SkipUnless condition around the
     * original argument.
     *
     * @var array<string, class-string>
     */
    public const array SKIP_UNLESS_CONDITIONS = [
        'RequiresPhpExtension' => ExtensionLoaded::class,
        'RequiresOperatingSystemFamily' => OperatingSystemFamily::class,
    ];

    /**
     * Coverage metadata and diagnostics with no runtime effect under
     * Greenlight. Their removal does not change what the tests do.
     *
     * @var list<string>
     */
    public const array DROPS = [
        'CoversClass',
        'CoversFunction',
        'CoversMethod',
        'CoversNothing',
        'CoversTrait',
        'UsesClass',
        'UsesFunction',
        'UsesMethod',
        'UsesTrait',
        'TestDox',
        'DisableReturnValueGenerationForTestDoubles',
    ];

    /**
     * Lifecycle attributes that the converter replaces through method
     * classification, not through an in-place rename.
     *
     * @var list<string>
     */
    public const array STRUCTURAL = [
        'Test',
        'Before',
        'After',
    ];

    public const string TEST_WITH = 'TestWith';

    /** @codeCoverageIgnore */
    private function __construct() {}
}
