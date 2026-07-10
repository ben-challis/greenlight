<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;

/**
 * Runs the real PHPStan binary with the shipped extension against probe test
 * classes: providers and inline rows matching the method signature must
 * analyse clean, while missing providers, wrong visibility, non-iterable
 * returns, arity mismatches, and wrong argument types must be flagged.
 */
final class PhpStanDataProviderRuleTest
{
    #[Test]
    public function providerAndRowShapesAreCheckedAgainstTheSignature(): void
    {
        $root = \dirname(__DIR__, 2);
        $probeDir = \sys_get_temp_dir() . '/greenlight-phpstan-provider-probe-' . \bin2hex(\random_bytes(4));
        \mkdir($probeDir, 0o777, true);

        $good = $probeDir . '/GoodProviderProbe.php';
        $bad = $probeDir . '/BadProviderProbe.php';

        \file_put_contents($good, <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace GreenlightProviderProbe;

            use Greenlight\Attribute\DataRow;
            use Greenlight\Attribute\DataSet;
            use Greenlight\Attribute\Test;

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

                /**
                 * @return iterable<string, array{int, int, int}>
                 */
                public static function sums(): iterable
                {
                    yield 'ones' => [1, 1, 2];
                }
            }
            PHP);

        \file_put_contents($bad, <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace GreenlightProviderProbe;

            use Greenlight\Attribute\DataRow;
            use Greenlight\Attribute\DataSet;
            use Greenlight\Attribute\Test;

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
            }
            PHP);

        try {
            $command = \sprintf(
                'cd %s && php vendor/bin/phpstan analyse --no-progress --error-format=json -c tests/Fixture/PhpStanExtension/probe.neon %s %s 2>/dev/null',
                \escapeshellarg($root),
                \escapeshellarg($good),
                \escapeshellarg($bad),
            );

            \exec($command, $output, $exitCode);
            $report = \json_decode(\implode('', $output), true);
        } finally {
            @\unlink($good);
            @\unlink($bad);
            @\rmdir($probeDir);
        }

        Expect::that(\is_array($report))->toBeTrue();
        \assert(\is_array($report) && \is_array($report['files']));

        $badFile = $report['files'][$bad] ?? [];
        \assert(\is_array($badFile));
        $badErrors = \is_array($badFile['messages'] ?? null) ? $badFile['messages'] : [];
        $messages = \implode("\n", \array_filter(\array_column($badErrors, 'message'), \is_string(...)));

        Expect::that($exitCode)->toBe(1)
            ->and(isset($report['files'][$good]))->toBeFalse()
            ->and(\count($badErrors))->toBe(7)
            ->and($messages)->toContain('Data provider doesNotExist() for missingProvider() does not exist')
            ->and($messages)->toContain('notStatic() must be public and static')
            ->and($messages)->toContain('notIterable() must return an iterable of argument arrays, returns string')
            ->and($messages)->toContain('scalarRows() must yield arrays of arguments, yields int')
            ->and($messages)->toContain('Data provider stringRows() row argument #1 of typedRows() expects int, string given')
            ->and($messages)->toContain('#[DataRow] supplies 2 arguments, but tooManyInline() expects exactly 1')
            ->and($messages)->toContain('#[DataRow] argument #1 of wrongInlineType() expects int, string given');
    }
}
