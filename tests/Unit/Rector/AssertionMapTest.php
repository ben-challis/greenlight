<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Rector;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Expectation;
use Greenlight\Rector\AssertionMap;

final class AssertionMapTest
{
    #[Test]
    public function everyMatcherExistsOnExpectationWithMatchingRequiredParameters(): void
    {
        $reflection = new \ReflectionClass(Expectation::class);

        foreach (AssertionMap::entries() as $assertion => $conversion) {
            Expect::that(\str_starts_with($assertion, 'assert'))->toBeTrue()
                ->and($reflection->hasMethod($conversion->matcher))->toBeTrue();

            $method = $reflection->getMethod($conversion->matcher);
            $matcherArgumentCount = \count($conversion->matcherArguments);
            $highestSourceIndex = \max([$conversion->subject, ...$conversion->matcherArguments]);

            Expect::that($method->isPublic())->toBeTrue()
                ->and($method->getNumberOfRequiredParameters())->toBeLessThanOrEqual($matcherArgumentCount)
                ->and($method->getNumberOfParameters())->toBeGreaterThanOrEqual($matcherArgumentCount)
                ->and($conversion->arity)->toBeGreaterThan($highestSourceIndex);
        }
    }

    #[Test]
    public function lookupIsCaseInsensitiveAndUnknownNamesReturnNull(): void
    {
        $conversion = AssertionMap::lookup('ASSERTSAME');

        Expect::that($conversion)->not()->toBeNull()
            ->and($conversion?->matcher)->toBe('toBe')
            ->and(AssertionMap::lookup('assertObjectHasProperty'))->toBeNull()
            ->and(AssertionMap::lookup('createMock'))->toBeNull();
    }
}
