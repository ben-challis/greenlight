<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Rector;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Group;
use Greenlight\Attribute\Isolated;
use Greenlight\Attribute\NoExpectations;
use Greenlight\Attribute\Test;
use Greenlight\Condition\ExtensionLoaded;
use Greenlight\Condition\OperatingSystemFamily;
use Greenlight\Rector\PhpUnitAttributes;

use function Greenlight\expect;

final class PhpUnitAttributesTest
{
    #[Test]
    public function conversionTablesDescribeTheSupportedPhpUnitAttributesExactly(): void
    {
        expect(PhpUnitAttributes::RENAMES)
            ->because('the Rector MUST preserve the supported PHPUnit attribute semantics')
            ->toBe([
                'DataProvider' => DataSet::class,
                'Group' => Group::class,
                'Ticket' => Group::class,
                'RunInSeparateProcess' => Isolated::class,
                'RunTestsInSeparateProcesses' => Isolated::class,
                'DoesNotPerformAssertions' => NoExpectations::class,
            ]);
        expect(PhpUnitAttributes::SIZE_GROUPS)
            ->toBe([
                'Small' => 'small',
                'Medium' => 'medium',
                'Large' => 'large',
            ]);
        expect(PhpUnitAttributes::SKIP_UNLESS_CONDITIONS)
            ->toBe([
                'RequiresPhpExtension' => ExtensionLoaded::class,
                'RequiresOperatingSystemFamily' => OperatingSystemFamily::class,
            ]);
        expect(PhpUnitAttributes::STRUCTURAL)
            ->toBe(['Test', 'Before', 'After']);
        expect(PhpUnitAttributes::TEST_WITH)
            ->toBe('TestWith');
    }

    #[Test]
    public function onlyInertPhpUnitAttributesAreDropped(): void
    {
        expect(PhpUnitAttributes::DROPS)
            ->because('the Rector MUST drop only PHPUnit metadata without Greenlight runtime behavior')
            ->toBe([
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
            ]);
    }
}
