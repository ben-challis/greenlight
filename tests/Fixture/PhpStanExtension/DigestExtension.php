<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\PhpStanExtension;

use Greenlight\Doubles\Fake;
use Greenlight\Expect\ExpectationExtension;

final class DigestExtension implements ExpectationExtension, Fake
{
    #[\Override]
    public function matchers(): array
    {
        return [
            'toBeHexadecimal' => static fn(string $subject): bool => \preg_match('/^[0-9a-f]+$/', $subject) === 1,
            'toHaveDigestLength' => static fn(string $subject, int $length): bool => \strlen($subject) === $length,
            'toBePositive' => static fn(int $subject): bool => $subject > 0,
        ];
    }
}
