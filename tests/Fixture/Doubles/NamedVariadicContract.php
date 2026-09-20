<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Doubles;

interface NamedVariadicContract
{
    public function record(string ...$values): void;

    public function withPrefix(string $prefix = 'default', string ...$values): void;

    public function mutate(string &$prefix, string &...$values): void;
}
