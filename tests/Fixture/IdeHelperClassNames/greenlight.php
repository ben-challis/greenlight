<?php

declare(strict_types=1);

use Greenlight\Config\GreenlightConfig;
use Greenlight\Tests\Fixture\IdeHelperClassNames\ClassNamesExtension;

return GreenlightConfig::create()
    ->plugins(static fn(): ClassNamesExtension => new ClassNamesExtension());
