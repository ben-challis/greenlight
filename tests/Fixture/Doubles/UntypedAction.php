<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Doubles;

interface UntypedAction
{
    /** @return mixed */
    public function perform(string $value);
}
