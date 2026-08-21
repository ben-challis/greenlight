<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\PhpStanScopedMatcher;

use Greenlight\Expect\ExpectationExtension;

final class ScopedMatcherExtension extends MatcherSubject implements ExpectationExtension
{
    #[\Override]
    public function matchers(): array
    {
        return [
            'toAcceptSelf' => static fn(self $subject): bool => true,
            'toAcceptParent' => static fn(parent $subject): bool => true,
            'toAcceptSelfArgument' => static fn(mixed $subject, self $other): bool => true,
            'toAcceptParentArgument' => static fn(mixed $subject, parent $other): bool => true,
        ];
    }
}
