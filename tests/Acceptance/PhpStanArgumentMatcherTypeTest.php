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
    public function typePreservesKnownNativeTypesAndFallsBackForOtherNames(): void
    {
        $probe = PhpStanProbe::analyze(
            $this->tempDirectory,
            <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Doubles\Argument;
            use Greenlight\Doubles\ArgumentMatcher;

            /**
             * @return array{
             *     ArgumentMatcher<array<array-key, mixed>>,
             *     ArgumentMatcher<bool>,
             *     ArgumentMatcher<float>,
             *     ArgumentMatcher<int>,
             *     ArgumentMatcher<null>,
             *     ArgumentMatcher<string>,
             *     ArgumentMatcher<DateTimeInterface>,
             *     ArgumentMatcher<mixed>,
             * }
             */
            function greenlightKnownArgumentMatcherTypes(): array
            {
                return [
                    Argument::type('array'),
                    Argument::type('bool'),
                    Argument::type('float'),
                    Argument::type('int'),
                    Argument::type('null'),
                    Argument::type('string'),
                    Argument::type(DateTimeInterface::class),
                    Argument::type('resource (stream)'),
                ];
            }
            PHP,
            <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Doubles\Argument;
            use Greenlight\Doubles\ArgumentMatcher;

            /** @return ArgumentMatcher<string> */
            function greenlightWrongKnownArgumentMatcherType(): ArgumentMatcher
            {
                return Argument::type('int');
            }

            /** @return ArgumentMatcher<int> */
            function greenlightWrongFallbackArgumentMatcherType(): ArgumentMatcher
            {
                return Argument::type('resource (stream)');
            }
            PHP,
        );

        Expect::that($probe->exitCode)->because('PHPStan MUST preserve known argument matcher types')->toBe(1);
        Expect::that($probe->goodPassed)->because('PHPStan messages: ' . $probe->messages())->toBeTrue();
        Expect::that(\count($probe->errors))->toBe(2);
        Expect::that($probe->messages())->toContain('ArgumentMatcher<int>');
        Expect::that($probe->messages())->toContain('ArgumentMatcher<mixed>');
    }

    #[Test]
    public function typeCombinationsPreserveUnionAndIntersectionTypes(): void
    {
        $probe = PhpStanProbe::analyze(
            $this->tempDirectory,
            <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Doubles\Argument;
            use Greenlight\Doubles\ArgumentMatcher;

            interface FirstCombinedArgumentType {}
            interface SecondCombinedArgumentType {}

            /**
             * @template T of object
             * @param class-string<T> $type
             * @return ArgumentMatcher<T|string>
             */
            function greenlightDynamicCombinedArgumentMatcherType(string $type): ArgumentMatcher
            {
                return Argument::union($type, 'string');
            }

            /**
             * @return array{
             *     ArgumentMatcher<FirstCombinedArgumentType&SecondCombinedArgumentType>,
             *     ArgumentMatcher<FirstCombinedArgumentType|SecondCombinedArgumentType>,
             *     ArgumentMatcher<int|string>,
             *     ArgumentMatcher<FirstCombinedArgumentType>,
             *     ArgumentMatcher<mixed>,
             * }
             */
            function greenlightCombinedArgumentMatcherTypes(): array
            {
                return [
                    Argument::intersection(FirstCombinedArgumentType::class, SecondCombinedArgumentType::class),
                    Argument::union(FirstCombinedArgumentType::class, SecondCombinedArgumentType::class),
                    Argument::union('int', 'string'),
                    Argument::intersection(FirstCombinedArgumentType::class, 'resource (stream)'),
                    Argument::union(FirstCombinedArgumentType::class, 'resource (stream)'),
                ];
            }
            PHP,
            <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Doubles\Argument;
            use Greenlight\Doubles\ArgumentMatcher;

            interface FirstWrongCombinedArgumentType {}
            interface SecondWrongCombinedArgumentType {}

            /** @return ArgumentMatcher<FirstWrongCombinedArgumentType> */
            function greenlightWrongCombinedArgumentMatcherType(): ArgumentMatcher
            {
                return Argument::union(
                    FirstWrongCombinedArgumentType::class,
                    SecondWrongCombinedArgumentType::class,
                );
            }
            PHP,
        );

        Expect::that($probe->exitCode)->because('PHPStan MUST preserve combined argument matcher types')->toBe(1);
        Expect::that($probe->goodPassed)->because('PHPStan messages: ' . $probe->messages())->toBeTrue();
        Expect::that(\count($probe->errors))->toBe(1);
        Expect::that($probe->messages())
            ->toContain('ArgumentMatcher<FirstWrongCombinedArgumentType|SecondWrongCombinedArgumentType>');
    }

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
