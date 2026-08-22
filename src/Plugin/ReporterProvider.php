<?php

declare(strict_types=1);

namespace Greenlight\Plugin;

use Greenlight\Reporting\ReporterDefinition;

/**
 * Supplies named reporter factories to the command-line reporter registry.
 *
 * Greenlight calls `reporters()` one time for a command. It calls a selected
 * factory for each run, including each repeat or watch run.
 */
interface ReporterProvider extends Plugin
{
    /**
     * Return a new reporter for each factory call. Do not close the supplied
     * output because Greenlight owns it.
     *
     * @return list<ReporterDefinition>
     */
    public function reporters(): array;
}
