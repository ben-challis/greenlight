<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\RequiresResource;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Tests\Support\PhpStanProbe;

#[RequiresResource('analysis-process')]
final readonly class PhpStanAttributeArgumentRuleTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function attributeArgumentsMustBeInTheirValidDomains(): void
    {
        $probe = PhpStanProbe::analyze(
            $this->tempDirectory,
            <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace GreenlightAttributeArgumentProbe;

            use Greenlight\Attribute\RequiresResource;
            use Greenlight\Attribute\Retry;
            use Greenlight\Attribute\SkipUnless;
            use Greenlight\Attribute\Timeout;
            use Greenlight\Condition\EnvironmentVariableEquals;

            #[RequiresResource('postgres.primary')]
            #[Retry(1)]
            #[SkipUnless(EnvironmentVariableEquals::class, 'APP_ENV', 'test')]
            #[Timeout(0.5)]
            final class GoodAttributeArgumentProbe {}
            PHP,
            <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace GreenlightAttributeArgumentProbe;

            use Greenlight\Attribute\RequiresResource;
            use Greenlight\Attribute\Retry;
            use Greenlight\Attribute\SkipUnless;
            use Greenlight\Attribute\Timeout;
            use Greenlight\Condition\EnvironmentVariableSet;
            use Greenlight\Core\Condition;

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
            PHP,
        );

        Expect::that($probe->exitCode)->because('attribute arguments must have valid values')->toBe(1);
        Expect::that($probe->goodPassed)->toBeTrue();
        Expect::that(\count($probe->errors))->toBe(9);
        Expect::that($probe->messages())->toContain('#[RequiresResource] name "Postgres primary" does not match')
            ->toContain('#[Retry] times must be at least 1')
            ->toContain('#[SkipUnless] argument 1 must be a scalar or null')
            ->toContain('#[SkipUnless] argument 1 must be a finite float')
            ->not()->toContain('#[SkipUnless] argument 0')
            ->toContain('#[Timeout] seconds must be finite and greater than zero');
    }
}
