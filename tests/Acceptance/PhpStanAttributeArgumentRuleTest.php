<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\RequiresResource;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\PhpStanProbe;

#[RequiresResource('analysis-process')]
final readonly class PhpStanAttributeArgumentRuleTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function attributeArgumentsMustBeInTheirValidDomains(): void
    {
        $probe = PhpStanProbe::analyze(
            $this->tempDirectory,
            <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace GreenlightAttributeArgumentProbe;

            use Greenlight\Attribute\DataRow;
            use Greenlight\Attribute\Group;
            use Greenlight\Attribute\RequiresResource;
            use Greenlight\Attribute\Retry;
            use Greenlight\Attribute\Skip;
            use Greenlight\Attribute\SkipUnless;
            use Greenlight\Attribute\Test;
            use Greenlight\Attribute\Timeout;
            use Greenlight\Condition\EnvironmentVariableEquals;

            abstract class GoodAbstractRetryFailure extends \RuntimeException {}

            #[Group('analysis')]
            #[RequiresResource('postgres.primary')]
            #[Retry(1, \RuntimeException::class)]
            #[Skip('not applicable in the probe')]
            #[SkipUnless(EnvironmentVariableEquals::class, 'APP_ENV', 'test')]
            #[Timeout(0.5)]
            final class GoodAttributeArgumentProbe
            {
                #[Test]
                #[DataRow([], label: null)]
                public function acceptsAnUnlabeledDataRow(): void {}
            }

            #[Retry(1, \Throwable::class)]
            final class GoodThrowableInterfaceRetryProbe {}

            #[Retry(1, GoodAbstractRetryFailure::class)]
            final class GoodAbstractRetryProbe {}
            PHP,
            <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace GreenlightAttributeArgumentProbe;

            use Greenlight\Attribute\DataRow;
            use Greenlight\Attribute\Group;
            use Greenlight\Attribute\RequiresResource;
            use Greenlight\Attribute\Retry;
            use Greenlight\Attribute\Skip;
            use Greenlight\Attribute\SkipUnless;
            use Greenlight\Attribute\Test;
            use Greenlight\Attribute\Timeout;
            use Greenlight\Condition\EnvironmentVariableSet;
            use Greenlight\Core\Condition;

            abstract class AbstractCondition implements Condition {}

            final readonly class FloatCondition implements Condition
            {
                public function __construct(private float $value) {}

                public function isSatisfied(): bool
                {
                    return is_finite($this->value);
                }
            }

            #[RequiresResource('Postgres primary')]
            #[Retry(0)]
            #[SkipUnless(EnvironmentVariableSet::class, ['APP_ENV'])]
            #[Timeout(0.0)]
            final class BadAttributeArgumentProbe {}

            #[SkipUnless(extra: ['APP_ENV'], condition: EnvironmentVariableSet::class)]
            final class BadNamedAttributeArgumentProbe {}

            #[RequiresResource(name: 'Postgres primary')]
            #[Retry(times: 0)]
            #[Timeout(seconds: 0.0)]
            final class BadNamedDomainAttributeArgumentProbe {}

            #[SkipUnless(FloatCondition::class, 1.0e1000)]
            final class BadNonFiniteSkipUnlessArgumentProbe {}

            #[Retry(1, \stdClass::class)]
            final class BadRetryTypeProbe {}

            #[SkipUnless(\stdClass::class)]
            final class BadConditionTypeProbe {}

            #[SkipUnless(AbstractCondition::class)]
            final class BadAbstractConditionProbe {}

            #[Group('')]
            #[Skip('')]
            final class BadEmptyStringAttributeProbe {}

            final class BadEmptyDataRowLabelProbe
            {
                #[Test]
                #[DataRow([], '')]
                public function hasAnEmptyLabel(): void {}
            }
            PHP,
        );

        Expect::that($probe->exitCode)->because('attribute arguments must have valid values')->toBe(1);
        Expect::that($probe->goodPassed)->toBeTrue();
        Expect::that(\count($probe->errors))->toBe(20);
        Expect::that($probe->messages())->toContain('#[RequiresResource] name "Postgres primary" does not match')
            ->toContain('#[Retry] times must be at least 1')
            ->toContain('#[Retry] onlyOn must name a Throwable type')
            ->toContain('#[SkipUnless] condition must name an instantiable Condition class')
            ->toContain('#[SkipUnless] argument 1 must be a scalar or null')
            ->toContain('#[SkipUnless] argument 1 must be a finite float')
            ->not()->toContain('#[SkipUnless] argument 0')
            ->toContain('#[Group] name must not be empty')
            ->toContain('#[Skip] reason must not be empty')
            ->toContain('#[DataRow] label must not be empty')
            ->toContain('#[Timeout] seconds must be finite and greater than zero');
    }
}
