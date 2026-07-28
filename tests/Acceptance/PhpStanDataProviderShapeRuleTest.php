<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\RequiresResource;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Tests\Support\PhpStanProbe;

#[RequiresResource('analysis-process')]
final readonly class PhpStanDataProviderShapeRuleTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function providerAndRowShapesAreCheckedAgainstTheSignature(): void
    {
        $probe = PhpStanProbe::analyze(
            $this->tempDirectory,
            <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace GreenlightProviderProbe;

            use Greenlight\Attribute\DataRow;
            use Greenlight\Attribute\DataSet;
            use Greenlight\Attribute\Test;

            final class SharedProviders
            {
                /**
                 * @return iterable<string, array{int, int, int}>
                 */
                public static function sums(int $unused = 1): iterable
                {
                    yield 'ones' => [1, 1, 2];
                }
            }

            final class GoodProviderProbe
            {
                #[Test]
                #[DataSet('sums')]
                #[DataRow([2, 2, 4], 'inline pair')]
                public function adds(int $left, int $right, int $expected): void
                {
                    echo $left + $right === $expected;
                }

                #[Test]
                #[DataRow(['solo'])]
                #[DataRow(['pair', 2])]
                public function optionalTail(string $label, int $count = 1): void
                {
                    echo $label, $count;
                }

                #[Test]
                #[DataSet(SharedProviders::class, 'sums')]
                public function addsFromSharedProvider(int $left, int $right, int $expected): void
                {
                    echo $left + $right === $expected;
                }

                /**
                 * @return iterable<string, array{int, int, int}>
                 */
                public static function sums(): iterable
                {
                    yield 'ones' => [1, 1, 2];
                }
            }
            PHP,
            <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace GreenlightProviderProbe;

            use Greenlight\Attribute\DataRow;
            use Greenlight\Attribute\DataSet;
            use Greenlight\Attribute\Test;

            abstract class SharedBadProviders
            {
                /**
                 * @return iterable<string, array{int}>
                 */
                public function notStatic(): iterable
                {
                    yield 'one' => [1];
                }

                /**
                 * @return iterable<string, array{int}>
                 */
                abstract public static function abstractProvider(): iterable;
            }

            final class BadProviderProbe
            {
                #[Test]
                #[DataSet('doesNotExist')]
                public function missingProvider(int $value): void
                {
                    echo $value;
                }

                #[Test]
                #[DataSet('notStatic')]
                public function instanceProvider(int $value): void
                {
                    echo $value;
                }

                #[Test]
                #[DataSet('notIterable')]
                public function stringProvider(int $value): void
                {
                    echo $value;
                }

                #[Test]
                #[DataSet('scalarRows')]
                public function rowsMustBeArrays(int $value): void
                {
                    echo $value;
                }

                #[Test]
                #[DataSet('stringRows')]
                public function typedRows(int $value): void
                {
                    echo $value;
                }

                #[Test]
                #[DataSet('requiredArgument')]
                public function providerMustBeCallableWithoutArguments(int $value): void
                {
                    echo $value;
                }

                #[Test]
                #[DataSet(SharedBadProviders::class, 'notStatic')]
                public function externalInstanceProvider(int $value): void
                {
                    echo $value;
                }

                #[Test]
                #[DataSet(SharedBadProviders::class, 'abstractProvider')]
                public function providerMustBeConcrete(int $value): void
                {
                    echo $value;
                }

                #[Test]
                #[DataRow([1, 2])]
                public function tooManyInline(int $value): void
                {
                    echo $value;
                }

                #[Test]
                #[DataRow(['text'])]
                public function wrongInlineType(int $value): void
                {
                    echo $value;
                }

                /**
                 * @return iterable<string, array{int}>
                 */
                public function notStatic(): iterable
                {
                    yield 'one' => [1];
                }

                public static function notIterable(): string
                {
                    return 'nope';
                }

                /**
                 * @return iterable<int, int>
                 */
                public static function scalarRows(): iterable
                {
                    yield 1;
                }

                /**
                 * @return iterable<string, array{string}>
                 */
                public static function stringRows(): iterable
                {
                    yield 'wrong' => ['text'];
                }

                /**
                 * @return iterable<string, array{int}>
                 */
                public static function requiredArgument(int $value): iterable
                {
                    yield 'value' => [$value];
                }
            }
            PHP,
        );

        Expect::that($probe->exitCode)->because('provider and row shapes are checked against the signature')->toBe(1)
            ->and($probe->goodPassed)->toBeTrue()
            ->and(\count($probe->errors))->toBe(10)
            ->and($probe->messages())->toContain('Data provider doesNotExist() for missingProvider() does not exist')
            ->toContain('notStatic() must be public and static')
            ->toContain('notIterable() must return an iterable of argument arrays. It returns string')
            ->toContain('scalarRows() must provide argument arrays. The iterable has value type int')
            ->toContain('Data provider stringRows() row argument #1 for typedRows() has type string, but the parameter requires int')
            ->toContain('requiredArgument() must not require arguments')
            ->toContain('SharedBadProviders::notStatic() must be public and static')
            ->toContain('SharedBadProviders::abstractProvider() must be concrete')
            ->toContain('#[DataRow] supplies 2 arguments, but tooManyInline() expects exactly 1')
            ->toContain('#[DataRow] argument #1 for wrongInlineType() has type string, but the parameter requires int');
    }

}
