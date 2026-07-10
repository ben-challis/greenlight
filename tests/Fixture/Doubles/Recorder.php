<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Doubles;

interface Recorder
{
    public function record(mixed $value): void;
}
