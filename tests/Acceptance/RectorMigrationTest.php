<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Rector\PhpUnitToGreenlightRector;
use Greenlight\Tests\Support\GreenlightCli;
use Greenlight\Tests\Support\RectorProbe;

final readonly class RectorMigrationTest
{
    private const string CONVERTIBLE = <<<'PHP_WRAP'
    <?php

    declare(strict_types=1);

    namespace App\Tests;

    use PHPUnit\Framework\Attributes\CoversClass;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\Attributes\DoesNotPerformAssertions;
    use PHPUnit\Framework\Attributes\Group;
    use PHPUnit\Framework\Attributes\RequiresPhpExtension;
    use PHPUnit\Framework\Attributes\RunInSeparateProcess;
    use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
    use PHPUnit\Framework\Attributes\Test;
    use PHPUnit\Framework\Attributes\TestWith;
    use PHPUnit\Framework\TestCase;

    #[CoversClass('App\Price')]
    #[Group('pricing')]
    #[RunTestsInSeparateProcesses]
    final class ProbeTest extends TestCase
    {
        private array $rates = [];

        protected function setUp(): void
        {
            parent::setUp();
            $this->rates = ['default' => '0.2'];
        }

        public function testFormatsTotals(): void
        {
            $this->assertSame('19.98', '19.98');
            $this->assertNotNull($this->rates);
            self::assertCount(1, $this->rates);
            $this->assertEqualsWithDelta(0.3, 0.1 + 0.2, 0.001);
        }

        #[Test]
        #[DataProvider('quantities')]
        public function multipliesQuantities(int $quantity): void
        {
            $this->assertGreaterThan(0, $quantity);
        }

        #[TestWith([2], name: 'two')]
        #[RequiresPhpExtension('spl')]
        public function testWithRows(int $value): void
        {
            static::assertTrue($value > 0);
        }

        public function testRejectsNegatives(): void
        {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('negative');
            $this->divide(-1);
        }

        #[RunInSeparateProcess]
        public function testSkipsWithoutServer(): void
        {
            if (!\getenv('GREENLIGHT_PROBE_SMTP')) {
                $this->markTestSkipped('no smtp server');
            }

            $this->assertTrue(true);
        }

        #[DoesNotPerformAssertions]
        public function testWarmsCachesQuietly(): void
        {
            $this->expectNotToPerformAssertions();
            \strlen('warm');
        }

        public function testFailsExplicitly(): void
        {
            if (\PHP_INT_SIZE < 4) {
                $this->fail('unsupported platform');
            }

            $this->assertTrue(true);
        }

        public static function quantities(): iterable
        {
            yield 'one' => [1];
        }

        private function divide(int $quantity): int
        {
            if ($quantity < 0) {
                throw new \InvalidArgumentException('negative quantity');
            }

            return \intdiv(10, $quantity);
        }
    }

    PHP_WRAP;

    private const string MESSAGED = <<<'PHP_WRAP'
    <?php

    declare(strict_types=1);

    namespace App\Tests;

    use PHPUnit\Framework\TestCase;

    final class ProbeTest extends TestCase
    {
        public function testComparesValues(): void
        {
            $this->assertSame('a', 'a', 'values must match');
        }
    }

    PHP_WRAP;

    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function convertsAPhpUnitClassAndTheResultRunsGreen(): void
    {
        $probe = RectorProbe::convert($this->tempDirectory, self::CONVERTIBLE, name: 'converts');

        Expect::that($probe->changed)->toBeTrue()
            ->and($probe->code)->not()->toContain('extends TestCase')
            ->toContain('#[\Greenlight\Attribute\Test]')
            ->toContain('#[\Greenlight\Attribute\Before]')
            ->toContain("#[\Greenlight\Attribute\Group('pricing')]")
            ->toContain("#[\Greenlight\Attribute\DataSet('quantities')]")
            ->toContain("#[\Greenlight\Attribute\DataRow([2], label: 'two')]")
            ->toContain("#[\Greenlight\Attribute\SkipUnless(\Greenlight\Condition\ExtensionLoaded::class, 'spl')]")
            ->toContain('#[\Greenlight\Attribute\Isolated]')
            ->toContain('#[\Greenlight\Attribute\NoExpectations]')
            ->toContain("\Greenlight\Expect\Expect::that('19.98')->toBe('19.98');")
            ->toContain('\Greenlight\Expect\Expect::that($this->rates)->not()->toBeNull();')
            ->toContain('\Greenlight\Expect\Expect::that(0.1 + 0.2)->toBeWithin(0.001, 0.3);')
            ->toContain("\Greenlight\Expect\Expect::that(fn() => \$this->divide(-1))->toThrow(\InvalidArgumentException::class, matching: '/negative/');")
            ->toContain("throw new \Greenlight\Core\Test\SkipTest('no smtp server');")
            ->toContain("\Greenlight\Expect\Fail::because('unsupported platform');")
            ->not()->toContain('#[CoversClass')
            ->not()->toContain('expectNotToPerformAssertions');

        $this->writeGreenlightConfig($probe->directory);
        $run = GreenlightCli::run($probe->directory, ['run', '--no-ansi']);

        Expect::that($run->exitCode)->toBe(0)
            ->and($run->stdout)->toContain('7 tests, 6 passed, 1 skipped')
            ->toContain('no smtp server');
    }

    #[Test]
    #[DataSet('unsupportedSources')]
    public function leavesClassesWithUnsupportedApiUntouched(string $source): void
    {
        $probe = RectorProbe::convert($this->tempDirectory, $source, name: 'unsupported');

        Expect::that($probe->changed)->toBeFalse()
            ->and($probe->code)->toBe($source);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsupportedSources(): iterable
    {
        yield 'mock creation' => [
            <<<'PHP_WRAP'
            <?php

            declare(strict_types=1);

            namespace App\Tests;

            use PHPUnit\Framework\TestCase;

            final class ProbeTest extends TestCase
            {
                public function testUsesAMock(): void
                {
                    $logger = $this->createMock('Psr\Log\LoggerInterface');
                    $this->assertNotNull($logger);
                }
            }

            PHP_WRAP,
        ];

        yield 'test dependencies' => [
            <<<'PHP_WRAP'
            <?php

            declare(strict_types=1);

            namespace App\Tests;

            use PHPUnit\Framework\Attributes\Depends;
            use PHPUnit\Framework\TestCase;

            final class ProbeTest extends TestCase
            {
                public function testProducesAValue(): int
                {
                    $this->assertTrue(true);

                    return 4;
                }

                #[Depends('testProducesAValue')]
                public function testConsumesAValue(int $value): void
                {
                    $this->assertSame(4, $value);
                }
            }

            PHP_WRAP,
        ];

        yield 'class-level fixtures' => [
            <<<'PHP_WRAP'
            <?php

            declare(strict_types=1);

            namespace App\Tests;

            use PHPUnit\Framework\TestCase;

            final class ProbeTest extends TestCase
            {
                public static function setUpBeforeClass(): void
                {
                }

                public function testSomething(): void
                {
                    $this->assertTrue(true);
                }
            }

            PHP_WRAP,
        ];

        yield 'non-final test hierarchy' => [
            <<<'PHP_WRAP'
            <?php

            declare(strict_types=1);

            namespace App\Tests;

            use PHPUnit\Framework\TestCase;

            class BaseTest extends TestCase
            {
                public function testBase(): void
                {
                    $this->assertTrue(true);
                }
            }

            final class ChildTest extends BaseTest
            {
                public function testChild(): void
                {
                    $this->assertTrue(true);
                }
            }

            PHP_WRAP,
        ];

        yield 'multiple data providers' => [
            <<<'PHP_WRAP'
            <?php

            declare(strict_types=1);

            namespace App\Tests;

            use PHPUnit\Framework\Attributes\DataProvider;
            use PHPUnit\Framework\TestCase;

            final class ProbeTest extends TestCase
            {
                #[DataProvider('positive')]
                #[DataProvider('negative')]
                public function testValues(int $value): void
                {
                    $this->assertIsInt($value);
                }

                public static function positive(): iterable
                {
                    yield [1];
                }

                public static function negative(): iterable
                {
                    yield [-1];
                }
            }

            PHP_WRAP,
        ];

        yield 'multiple extension requirements' => [
            <<<'PHP_WRAP'
            <?php

            declare(strict_types=1);

            namespace App\Tests;

            use PHPUnit\Framework\Attributes\RequiresPhpExtension;
            use PHPUnit\Framework\TestCase;

            final class ProbeTest extends TestCase
            {
                #[RequiresPhpExtension('json')]
                #[RequiresPhpExtension('spl')]
                public function testExtensions(): void
                {
                    $this->assertTrue(true);
                }
            }

            PHP_WRAP,
        ];

        yield 'class process isolation' => [
            <<<'PHP_WRAP'
            <?php

            declare(strict_types=1);

            namespace App\Tests;

            use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
            use PHPUnit\Framework\TestCase;

            #[RunClassInSeparateProcess]
            final class ProbeTest extends TestCase
            {
                public function testSomething(): void
                {
                    $this->assertTrue(true);
                }
            }

            PHP_WRAP,
        ];

        yield 'preserved process state' => [
            <<<'PHP_WRAP'
            <?php

            declare(strict_types=1);

            namespace App\Tests;

            use PHPUnit\Framework\Attributes\PreserveGlobalState;
            use PHPUnit\Framework\Attributes\RunInSeparateProcess;
            use PHPUnit\Framework\TestCase;

            final class ProbeTest extends TestCase
            {
                #[RunInSeparateProcess]
                #[PreserveGlobalState(true)]
                public function testSomething(): void
                {
                    $this->assertTrue(true);
                }
            }

            PHP_WRAP,
        ];

        yield 'PHP empty semantics' => [
            <<<'PHP_WRAP'
            <?php

            declare(strict_types=1);

            namespace App\Tests;

            use PHPUnit\Framework\TestCase;

            final class ProbeTest extends TestCase
            {
                public function testEmptyScalar(): void
                {
                    $this->assertEmpty(false);
                }
            }

            PHP_WRAP,
        ];

        yield 'existing non-repeatable Greenlight attribute' => [
            <<<'PHP_WRAP'
            <?php

            declare(strict_types=1);

            namespace App\Tests;

            use Greenlight\Attribute\Isolated;
            use PHPUnit\Framework\Attributes\RunInSeparateProcess;
            use PHPUnit\Framework\TestCase;

            final class ProbeTest extends TestCase
            {
                #[Isolated]
                #[RunInSeparateProcess]
                public function testSomething(): void
                {
                    $this->assertTrue(true);
                }
            }

            PHP_WRAP,
        ];

        yield 'assertion message without opting in' => [self::MESSAGED];
    }

    #[Test]
    public function dropsAssertionMessagesWhenConfigured(): void
    {
        $probe = RectorProbe::convert(
            $this->tempDirectory,
            self::MESSAGED,
            [PhpUnitToGreenlightRector::DROP_ASSERTION_MESSAGES => true],
            name: 'drops-messages',
        );

        Expect::that($probe->changed)->toBeTrue()
            ->and($probe->code)->toContain("\Greenlight\Expect\Expect::that('a')->toBe('a');")
            ->not()->toContain('values must match');
    }

    #[Test]
    public function preservesEachInlineDataRow(): void
    {
        $probe = RectorProbe::convert(
            $this->tempDirectory,
            <<<'PHP_WRAP'
            <?php

            declare(strict_types=1);

            namespace App\Tests;

            use PHPUnit\Framework\Attributes\TestWith;
            use PHPUnit\Framework\TestCase;

            final class ProbeTest extends TestCase
            {
                #[TestWith([1])]
                #[TestWith([2])]
                public function testRows(int $value): void
                {
                    $this->assertGreaterThan(0, $value);
                }
            }

            PHP_WRAP,
            name: 'inline-rows',
        );

        Expect::that($probe->changed)->toBeTrue()
            ->and(\substr_count($probe->code, '#[\Greenlight\Attribute\DataRow'))->toBe(2)
            ->and($probe->code)->toContain('#[\Greenlight\Attribute\DataRow([1])]')
            ->toContain('#[\Greenlight\Attribute\DataRow([2])]');

        $this->writeGreenlightConfig($probe->directory);
        $run = GreenlightCli::run($probe->directory, ['run', '--no-ansi']);

        Expect::that($run->exitCode)->toBe(0)
            ->and($run->stdout)->toContain('2 tests, 2 passed');
    }

    #[Test]
    public function convertsEverySupportedAssertionAndTheResultRunsGreen(): void
    {
        $probe = RectorProbe::convert(
            $this->tempDirectory,
            <<<'PHP_WRAP'
            <?php

            declare(strict_types=1);

            namespace App\Tests;

            use PHPUnit\Framework\TestCase;

            final class ProbeTest extends TestCase
            {
                public function testAssertions(): void
                {
                    $this->assertSame(1, 1);
                    $this->assertNotSame(1, '1');
                    $this->assertEquals(['a' => 1], ['a' => 1]);
                    $this->assertNotEquals(['a' => 1], ['a' => 2]);
                    $this->assertEqualsCanonicalizing([1, 2], [2, 1]);
                    $this->assertNotEqualsCanonicalizing([1, 2], [1, 3]);
                    $this->assertEqualsWithDelta(0.3, 0.1 + 0.2, 0.001);
                    $this->assertTrue(true);
                    $this->assertNotTrue(1);
                    $this->assertFalse(false);
                    $this->assertNotFalse(0);
                    $this->assertNull(null);
                    $this->assertNotNull(false);
                    $this->assertInstanceOf(\stdClass::class, new \stdClass());
                    $this->assertNotInstanceOf(\stdClass::class, new \ArrayObject());
                    $this->assertCount(2, [1, 2]);
                    $this->assertNotCount(1, [1, 2]);
                    $this->assertGreaterThan(1, 2);
                    $this->assertGreaterThanOrEqual(2, 2);
                    $this->assertLessThan(2, 1);
                    $this->assertLessThanOrEqual(1, 1);
                    $this->assertIsArray([]);
                    $this->assertIsNotArray(new \ArrayObject());
                    $this->assertIsString('');
                    $this->assertIsNotString(1);
                    $this->assertIsInt(1);
                    $this->assertIsNotInt(1.0);
                    $this->assertIsFloat(1.0);
                    $this->assertIsNotFloat(1);
                    $this->assertIsBool(true);
                    $this->assertIsNotBool(1);
                    $this->assertIsCallable(static fn(): null => null);
                    $this->assertIsNotCallable(null);
                    $this->assertIsIterable([]);
                    $this->assertIsNotIterable(1);
                    $this->assertContains(1, [1]);
                    $this->assertNotContains(2, [1]);
                    $this->assertStringContainsString('ell', 'hello');
                    $this->assertStringNotContainsString('bye', 'hello');
                    $this->assertArrayHasKey('a', ['a' => 1]);
                    $this->assertArrayNotHasKey('b', ['a' => 1]);
                    $this->assertMatchesRegularExpression('/ell/', 'hello');
                    $this->assertDoesNotMatchRegularExpression('/bye/', 'hello');
                    $this->assertStringStartsWith('hel', 'hello');
                    $this->assertStringStartsNotWith('bye', 'hello');
                    $this->assertStringEndsWith('llo', 'hello');
                    $this->assertStringEndsNotWith('bye', 'hello');
                    $this->assertJson('{"a":1}');
                    $this->assertJsonStringEqualsJsonString('{"a":1}', '{"a":1}');
                }
            }

            PHP_WRAP,
            name: 'assertions',
        );

        Expect::that($probe->changed)->toBeTrue()
            ->and($probe->code)->not()->toContain('->assert')
            ->not()->toContain('::assert');

        $this->writeGreenlightConfig($probe->directory);
        $run = GreenlightCli::run($probe->directory, ['run', '--no-ansi']);

        Expect::that($run->exitCode)->toBe(0)
            ->and($run->stdout)->toContain('1 test, 1 passed');
    }

    private function writeGreenlightConfig(string $directory): void
    {
        \file_put_contents($directory . '/greenlight.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Config\GreenlightConfig;

            require_once __DIR__ . '/tests/ProbeTest.php';

            return GreenlightConfig::create()
                ->paths([__DIR__ . '/tests'])
                ->workers(1);

            PHP);
    }
}
