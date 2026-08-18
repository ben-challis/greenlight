<?php

declare(strict_types=1);

use App\NamedGreeter;

return [
    'probe.named_greeter' => static fn(): NamedGreeter => new NamedGreeter(),
];
