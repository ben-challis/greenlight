<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\RequiresResource;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\PhpStanProbe;

#[RequiresResource('analysis-process')]
final readonly class PhpStanArgumentMatcherTypeTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function allOfPreservesTheTypesOfItsMatchers(): void
    {
        $probe = PhpStanProbe::analyze(
            $this->tempDirectory,
            <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Doubles\Argument;
            use Greenlight\Doubles\ArgumentMatcher;
            use Greenlight\Doubles\InvalidDoubleUsage;

            /**
             * @return ArgumentMatcher<DateTimeInterface>
             * @throws InvalidDoubleUsage
             */
            function greenlightGoodArgumentMatcherType(): ArgumentMatcher
            {
                return Argument::allOf(
                    Argument::type(DateTimeInterface::class),
                    Argument::predicate(
                        static fn(DateTimeInterface $value): bool => $value->getTimestamp() > 0,
                        'positive timestamp',
                    ),
                );
            }
            PHP,
            <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Doubles\Argument;
            use Greenlight\Doubles\ArgumentMatcher;
            use Greenlight\Doubles\InvalidDoubleUsage;

            /**
             * @return ArgumentMatcher<DateTimeInterface>
             * @throws InvalidDoubleUsage
             */
            function greenlightBadArgumentMatcherType(): ArgumentMatcher
            {
                return Argument::allOf(
                    Argument::type(DateTimeInterface::class),
                    Argument::predicate(
                        static fn(DateTimeZone $value): bool => $value->getName() !== '',
                        'named timezone',
                    ),
                );
            }
            PHP,
        );

        Expect::that($probe->exitCode)->because('PHPStan MUST preserve allOf() matcher types')->toBe(1);
        Expect::that($probe->goodPassed)->toBeTrue();
        Expect::that(\count($probe->errors))->toBe(1);
        Expect::that($probe->messages())
            ->toContain('ArgumentMatcher<DateTimeInterface|DateTimeZone>');
    }
}
