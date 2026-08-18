<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Expect;

final class HookedProperties
{
    public string $backed = 'stored' {
        get {
            throw new \RuntimeException('The backed getter MUST NOT render ' . $this->backed . '.');
        }
        set {
            $this->backed = $value;
        }
    }

    public string $virtual {
        get => throw new \RuntimeException('The virtual getter MUST NOT run during rendering.');
    }
}
