<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Doubles;

interface CloneableRecorder
{
    public function __clone(): void;

    public function record(string $value): void;
}
