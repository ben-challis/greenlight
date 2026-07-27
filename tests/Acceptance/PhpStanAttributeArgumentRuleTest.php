<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Tests\Support\PhpStanProbe;

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

            #[RequiresResource('Postgres primary')]
            #[Retry(0)]
            #[SkipUnless(EnvironmentVariableSet::class, ['APP_ENV'])]
            #[Timeout(0.0)]
            final class BadAttributeArgumentProbe {}

            #[SkipUnless(extra: ['APP_ENV'], condition: EnvironmentVariableSet::class)]
            final class BadNamedAttributeArgumentProbe {}
            PHP,
        );

        Expect::that($probe->exitCode)->because('attribute arguments must have valid values')->toBe(1)
            ->and($probe->goodPassed)->toBeTrue()
            ->and(\count($probe->errors))->toBe(5)
            ->and($probe->messages())->toContain('#[RequiresResource] name "Postgres primary" does not match')
            ->toContain('#[Retry] times must be at least 1')
            ->toContain('#[SkipUnless] argument 1 must be a scalar or null')
            ->not()->toContain('#[SkipUnless] argument 0')
            ->toContain('#[Timeout] seconds must be finite and greater than zero');
    }
}
