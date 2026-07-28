<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Doubles;

interface VariadicReference
{
    public function change(string &...$values): void;
}
