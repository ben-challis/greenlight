<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\RequiresResource;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Tests\Support\PhpStanProbe;

#[RequiresResource('analysis-process')]
final readonly class PhpStanDataProviderKeyRuleTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function providerKeysEmptyProvidersAndDuplicateRowKeysAreChecked(): void
    {
        $probe = PhpStanProbe::analyze(
            $this->tempDirectory,
            <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace GreenlightProviderKeyProbe;

            use Greenlight\Attribute\DataRow;
            use Greenlight\Attribute\DataSet;
            use Greenlight\Attribute\Test;

            final class GoodProviderKeyProbe
            {
                #[Test]
                #[DataRow([1])]
                #[DataRow([2], 'two')]
                #[DataSet('rows')]
                public function acceptsRows(int $value): void
                {
                    echo $value;
                }

                /**
                 * @return iterable<int|string, array{int}>
                 */
                public static function rows(): iterable
                {
                    yield 0 => [3];
                    yield 'four' => [4];
                }
            }
            PHP,
            <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace GreenlightProviderKeyProbe;

            use Greenlight\Attribute\DataRow;
            use Greenlight\Attribute\DataSet;
            use Greenlight\Attribute\Test;

            final class BadProviderKeyProbe
            {
                #[Test]
                #[DataRow([1], 'same')]
                #[DataRow([2], 'same')]
                public function duplicateLabels(int $value): void
                {
                    echo $value;
                }

                #[Test]
                #[DataRow([1])]
                #[DataRow([2], '#0')]
                public function duplicateGeneratedLabel(int $value): void
                {
                    echo $value;
                }

                #[Test]
                #[DataSet('invalidKeys')]
                public function invalidProviderKeys(int $value): void
                {
                    echo $value;
                }

                #[Test]
                #[DataSet('empty')]
                public function emptyProvider(int $value): void
                {
                    echo $value;
                }

                /**
                 * @return iterable<bool, array{int}>
                 */
                public static function invalidKeys(): iterable
                {
                    throw new \LogicException();
                }

                /**
                 * @return array{}
                 */
                public static function empty(): array
                {
                    return [];
                }
            }
            PHP,
        );

        Expect::that($probe->exitCode)->because('PHPStan checks provider keys, empty providers, and duplicate row keys')->toBe(1)
            ->and($probe->goodPassed)->toBeTrue()
            ->and(\count($probe->errors))->toBe(4)
            ->and($probe->messages())->toContain('#[DataRow] key "same" occurs more than once on duplicateLabels()')
            ->toContain('#[DataRow] key "#0" occurs more than once on duplicateGeneratedLabel()')
            ->toContain('invalidKeys() keys must be int or string. The provider returns keys of type bool')
            ->toContain('empty() must provide at least one argument array');
    }
}
