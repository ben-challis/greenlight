<?php

declare(strict_types=1);

namespace Greenlight\ConsumerSmoke;

use Greenlight\Expect\Expect;

Expect::value(42)->toContain(4);
