<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\RequiresResource;
use Greenlight\Attribute\Test;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\PhpStanProbe;
use Greenlight\Tests\Support\ProjectFiles;

use function Greenlight\expect;

#[RequiresResource('analysis-process')]
final readonly class PhpStanExpectFunctionTest
{
    public function __construct(private TemporaryDirectory $directory) {}

    #[Test]
    public function ordinaryPhpDocPreservesTheValueTypeWithoutTheExtension(): void
    {
        $files = ProjectFiles::create($this->directory, 'expect-function-analysis');
        $files->write('phpstan.neon', \sprintf(
            "parameters:\n    level: max\n    scanFiles:\n        - %s/src/Expect/functions.php\n",
            \dirname(__DIR__, 2),
        ));
        $probe = PhpStanProbe::analyze(
            $this->directory,
            <<<'PHP_WRAP'
            <?php

            use function Greenlight\expect;
            use function PHPStan\Testing\assertType;

            function valueFunctionTypes(string $value, \Closure $callback): void
            {
                assertType('Greenlight\Expect\Expectation<string>', expect($value));
                assertType('Greenlight\Expect\Expectation<Closure>', expect($callback));
                assertType('Greenlight\Expect\ExpectationBuilder', expect());
                assertType('Greenlight\Expect\Expectation<null>', expect(null));
                assertType('Greenlight\Expect\CallExpectation<int>', expect()->calling(static fn(): int => 1));
                assertType('Greenlight\Expect\ReturnValueExpectation<int>', expect()->calling(static fn(): int => 1)->returnValue());
                expect()->calling(static fn(): never => throw new \RuntimeException())->toThrow(\RuntimeException::class);
                expect($value)->toBe($value);
                expect($callback)->toBeCallable();
            }
            PHP_WRAP,
            <<<'PHP'
            <?php

            use function Greenlight\expect;

            expect(static fn(): int => 1)->toThrow(\RuntimeException::class);
            expect(static fn(): int => 1)->toReturn(1);
            expect(static fn(): int => 1)->eventually();
            PHP,
            $files->path('phpstan.neon'),
        );

        expect($probe->goodErrors)->toBe([]);
        expect($probe->exitCode)->toBe(1);
        expect(\count($probe->errors))->toBe(3);
        expect($probe->messages())->toContain('undefined method');
    }

    #[Test]
    public function theExtensionResolvesImportsAndDoesNotNarrowOtherFunctions(): void
    {
        $probe = PhpStanProbe::analyze(
            $this->directory,
            <<<'PHP_WRAP'
            <?php

            namespace FunctionNarrowing;

            use function Greenlight\expect as checkValue;
            use function PHPStan\Testing\assertType;

            function acceptText(string $value): void {}

            function helperNarrowing(?string $aliased, ?string $qualified, mixed $unknown, object $object): void
            {
                assertType('Greenlight\Expect\Expectation<mixed>', checkValue($unknown));
                assertType('Greenlight\Expect\Expectation<object>', checkValue($object));
                checkValue($unknown)->toBe($unknown);

                checkValue($aliased)->because('Text is required.')->not()->toBeNull();
                acceptText($aliased);

                \Greenlight\expect($qualified)->toBeString();
                acceptText($qualified);

            }
            PHP_WRAP,
            <<<'PHP'
            <?php

            namespace OtherFunctionNarrowing;

            use Greenlight\Expect\Expect;
            use Greenlight\Expect\Expectation;

            /** @return Expectation<mixed> */
            function expect(mixed $value): Expectation
            {
                return Expect::value($value);
            }

            function acceptText(string $value): void {}

            function unrelatedHelper(?string $value, ?string $stored, ?string $unpacked): void
            {
                expect($value)->not()->toBeNull();
                acceptText($value);

                $chain = \Greenlight\expect($stored);
                $chain->not()->toBeNull();
                acceptText($stored);

                \Greenlight\expect(...[$unpacked])->not()->toBeNull();
                acceptText($unpacked);
            }
            PHP,
        );

        expect($probe->goodErrors)->toBe([]);
        expect($probe->exitCode)->toBe(1);
        expect(\count($probe->errors))->toBe(3);
        expect($probe->messages())->toContain('expects string, string|null given');
    }
}
