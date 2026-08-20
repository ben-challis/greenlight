<?php

declare(strict_types=1);

require_once __DIR__ . '/tools/rector-config.php';

return \greenlightRectorConfig(
    [__DIR__ . '/src', __DIR__ . '/tests', __DIR__ . '/tools'],
    __DIR__ . '/build/cache/rector',
);
