<?php

declare(strict_types=1);

require_once __DIR__ . '/tools/rector-config.php';

return \greenlightRectorConfig(
    [__DIR__ . '/build/docs-php'],
    __DIR__ . '/build/cache/rector-docs',
);
