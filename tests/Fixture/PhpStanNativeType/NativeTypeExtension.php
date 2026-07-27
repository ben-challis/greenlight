<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\PhpStanNativeType;

use Greenlight\Doubles\Fake;
use Greenlight\Expect\ExpectationExtension;

final class NativeTypeExtension implements ExpectationExtension, Fake
{
    #[\Override]
    public function matchers(): array
    {
        return [
            'toAcceptNullableDateTime' => static fn(?\DateTimeInterface $subject): bool => true,
            'toAcceptIntegerOrString' => static fn(int|string $subject): bool => true,
            'toAcceptSerializableString' => static fn(\JsonSerializable&\Stringable $subject): bool => true,
        ];
    }
}
