<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Plugins;

use Greenlight\Doubles\Fake;
use Greenlight\Plugin\Plugin;

final class NamedFakePlugin implements Fake, Plugin {}
