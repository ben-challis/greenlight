<?php

declare(strict_types=1);

namespace Greenlight\Plugin;

/** Supplies named command-line commands. */
interface CommandProvider extends Plugin
{
    /** @return list<CommandDefinition> */
    public function commands(): array;
}
