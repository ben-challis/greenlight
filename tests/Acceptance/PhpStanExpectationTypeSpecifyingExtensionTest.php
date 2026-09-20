<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\RequiresResource;
use Greenlight\Attribute\Test;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\PhpStanProbe;

use function Greenlight\expect;

#[RequiresResource('analysis-process')]
final readonly class PhpStanExpectationTypeSpecifyingExtensionTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function synchronousTypeExpectationsNarrowTheOriginalSubject(): void
    {
        $probe = PhpStanProbe::analyze(
            $this->tempDirectory,
            <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Expect\Expect;

            final class NarrowedFirst {}
            final class NarrowedSecond {}

            /** @param array<mixed> $value */
            function acceptArray(array $value): void {}
            function acceptBool(bool $value): void {}
            function acceptCallable(callable $value): void {}
            function acceptFalse(false $value): void {}
            function acceptFloat(float $value): void {}
            function acceptInt(int $value): void {}
            /** @param iterable<mixed> $value */
            function acceptIterable(iterable $value): void {}
            function acceptNull(null $value): void {}
            function acceptSecond(NarrowedSecond $value): void {}
            function acceptString(string $value): void {}
            function acceptTrue(true $value): void {}

            /**
             * @param array<mixed>|string $array
             * @param iterable<mixed>|string $iterable
             */
            function greenlightGoodExpectationNarrowingProbe(
                array|string $array,
                bool|int $bool,
                callable|int $callable,
                bool $false,
                float|string $float,
                int|string $int,
                object $instance,
                iterable|string $iterable,
                ?string $nullableString,
                null|string $null,
                NarrowedFirst|NarrowedSecond $object,
                int|string $string,
                bool $true,
            ): void {
                Expect::value($array)->toBeArray();
                acceptArray($array);

                Expect::value($bool)->toBeBool();
                acceptBool($bool);

                Expect::value($callable)->toBeCallable();
                acceptCallable($callable);

                Expect::value($false)->not()->toBeTrue();
                acceptFalse($false);

                Expect::value($float)->toBeFloat();
                acceptFloat($float);

                Expect::value($int)->toBeInt();
                acceptInt($int);

                Expect::value($instance)->toBeInstanceOf(NarrowedSecond::class);
                acceptSecond($instance);

                Expect::value($iterable)->toBeIterable();
                acceptIterable($iterable);

                Expect::value($nullableString)
                    ->because('the value MUST not be null')
                    ->not()->toBeNull();
                acceptString($nullableString);

                Expect::value($null)->toBeNull();
                acceptNull($null);

                Expect::value($object)
                    ->because('the first type MUST be absent')
                    ->not()->toBeInstanceOf(NarrowedFirst::class);
                acceptSecond($object);

                Expect::value($string)->because('the value MUST be text')->toBeString();
                acceptString($string);

                Expect::value($true)->not()->toBeFalse();
                acceptTrue($true);
            }
            PHP,
            <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Expect\Expect;

            function acceptNarrowedString(string $value): void {}

            function greenlightUnsupportedExpectationNarrowingProbe(?string $stored, ?string $temporal): void
            {
                $expectation = Expect::value($stored);
                $expectation->not()->toBeNull();
                acceptNarrowedString($stored);

                Expect::calling(static fn(): ?string => $temporal)->returnValue()->eventually()->within(1.0)->not()->toBeNull();
                acceptNarrowedString($temporal);
            }
            PHP,
        );

        expect($probe->exitCode)
            ->because('PHPStan keeps unsafe stored and temporal subjects nullable')
            ->toBe(1);
        expect($probe->goodPassed)
            ->because('PHPStan messages: ' . $probe->messages())
            ->toBeTrue();
        expect(\count($probe->errors))->toBe(2);
        expect($probe->messages())->toContain('expects string, string|null given');
    }
}
