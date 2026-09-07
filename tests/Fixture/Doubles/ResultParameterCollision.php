<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Doubles;

interface ResultParameterCollision
{
    public function result(string &$__greenlightResult, string &$__greenlightResult_): int;

    public function &reference(string &$__greenlightResult): string;
}
