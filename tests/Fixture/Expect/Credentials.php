<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Expect;

final class Credentials
{
    public function __construct(
        public string $user,
        private readonly string $password,
    ) {}

    public function password(): string
    {
        return $this->password;
    }
}
