<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\PhpStanNativeType;

use Greenlight\Doubles\Fake;

final class SerializableString implements \JsonSerializable, \Stringable, Fake
{
    #[\Override]
    public function jsonSerialize(): string
    {
        return (string) $this;
    }

    #[\Override]
    public function __toString(): string
    {
        return '';
    }
}
