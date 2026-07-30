<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Config;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Config\GreenlightConfig;
use Greenlight\Config\InvalidConfiguration;
use Greenlight\Expect\Expect;

final class ResultPolicyConfigurationTest
{
    /**
     * @param \Closure(GreenlightConfig): void $configure
     * @param non-empty-string $field
     */
    #[Test]
    #[DataSet('policyFlags')]
    public function policyFlagsFlowIntoTheBuiltConfiguration(
        \Closure $configure,
        string $field,
        bool $expected,
    ): void {
        $builder = GreenlightConfig::create();
        $configure($builder);

        Expect::that($builder->build()->policy->toWire()[$field])
            ->because('the public configuration flag must reach the result policy')
            ->toBe($expected);
    }

    /**
     * @return iterable<string, array{\Closure(GreenlightConfig): void, non-empty-string, bool}>
     */
    public static function policyFlags(): iterable
    {
        yield 'fail on deprecation enabled' => [
            static function (GreenlightConfig $config): void {
                $config->failOnDeprecation();
            },
            'failOnDeprecation',
            true,
        ];
        yield 'fail on deprecation disabled' => [
            static function (GreenlightConfig $config): void {
                $config->failOnDeprecation()->failOnDeprecation(false);
            },
            'failOnDeprecation',
            false,
        ];
        yield 'fail on notice enabled' => [
            static function (GreenlightConfig $config): void {
                $config->failOnNotice();
            },
            'failOnNotice',
            true,
        ];
        yield 'fail on notice disabled' => [
            static function (GreenlightConfig $config): void {
                $config->failOnNotice()->failOnNotice(false);
            },
            'failOnNotice',
            false,
        ];
        yield 'fail on risky enabled' => [
            static function (GreenlightConfig $config): void {
                $config->failOnRisky();
            },
            'failOnRisky',
            true,
        ];
        yield 'fail on risky disabled' => [
            static function (GreenlightConfig $config): void {
                $config->failOnRisky()->failOnRisky(false);
            },
            'failOnRisky',
            false,
        ];
    }

    #[Test]
    public function ignoredDeprecationPatternsAccumulateInCallOrder(): void
    {
        $policy = GreenlightConfig::create()
            ->ignoreDeprecationsMatching('vendor *', 'legacy?')
            ->ignoreDeprecationsMatching('third-party')
            ->build()
            ->policy;

        Expect::that($policy->ignoreDeprecations)
            ->because('multiple public configuration calls add each pattern')
            ->toBe(['vendor *', 'legacy?', 'third-party']);
    }

    /**
     * @param list<string> $patterns
     */
    #[Test]
    #[DataSet('patternsContainingAnEmptyValue')]
    public function emptyIgnorePatternsGiveExactGuidance(array $patterns): void
    {
        Expect::that(
            static fn(): GreenlightConfig => GreenlightConfig::create()
                ->ignoreDeprecationsMatching(...$patterns),
        )
            ->because('empty deprecation ignore patterns are invalid')
            ->toThrow(
                InvalidConfiguration::class,
                message: 'ignoreDeprecationsMatching() patterns cannot be empty.',
            );
    }

    #[Test]
    public function rejectedIgnorePatternsDoNotRetainEarlierArguments(): void
    {
        $config = GreenlightConfig::create()
            ->ignoreDeprecationsMatching('existing');

        Expect::that(
            static fn(): GreenlightConfig => $config->ignoreDeprecationsMatching('partial', ''),
        )
            ->because('a rejected pattern list does not partially change the policy')
            ->toThrow(
                InvalidConfiguration::class,
                message: 'ignoreDeprecationsMatching() patterns cannot be empty.',
            );

        Expect::that($config->build()->policy->ignoreDeprecations)
            ->because('a rejected pattern call retains only prior patterns')
            ->toBe(['existing']);
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function patternsContainingAnEmptyValue(): iterable
    {
        yield 'only pattern' => [['']];
        yield 'first pattern' => [['', 'legacy']];
        yield 'later pattern' => [['legacy', '']];
    }
}
