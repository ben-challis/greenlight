<?php

declare(strict_types=1);

namespace Greenlight\Plugin;

use Greenlight\Coverage\CoverageMap;

/** Changes the merged coverage map before Greenlight exports it. */
interface CoverageMapTransformer extends Plugin
{
    public function transformCoverageMap(CoverageMap $coverage): CoverageMap;
}
