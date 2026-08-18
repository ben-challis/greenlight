<?php

declare(strict_types=1);

namespace App;

final class NamedGreeter
{
    public function greet(): string
    {
        return 'Named service';
    }
}
