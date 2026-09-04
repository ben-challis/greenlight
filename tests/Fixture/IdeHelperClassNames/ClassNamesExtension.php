<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\IdeHelperClassNames;

use Greenlight\Doubles\Fake;
use Greenlight\Expect\ExpectationExtension;

final class ClassNamesExtension implements ExpectationExtension, Fake
{
    /** @return array{toAcceptNames: \Closure(mixed, (\Countable&\Iterator<mixed, mixed>)|string, ?\DateTimeInterface, ClassNamesExtension): true} */
    #[\Override]
    public function matchers(): array
    {
        return [
            'toAcceptNames' => static fn(
                mixed $subject,
                (\Countable&\Iterator)|string $comparison,
                ?\DateTimeInterface $date,
                ClassNamesExtension $extension,
            ): bool => true,
        ];
    }
}
