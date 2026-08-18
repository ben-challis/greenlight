<?php

declare(strict_types=1);

use Hyperf\Context\ApplicationContext;
use Hyperf\Di\Container;
use Hyperf\Di\Definition\DefinitionSourceFactory;
use App\DisposalProbe;

$container = new Container((new DefinitionSourceFactory())());
DisposalProbe::containerCreated();

return ApplicationContext::setContainer($container);
