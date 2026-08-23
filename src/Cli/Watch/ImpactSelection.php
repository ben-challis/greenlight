<?php

declare(strict_types=1);

namespace Greenlight\Cli\Watch;

use Greenlight\Test\TestSelection;

/**
 * Contains one impacted selection and its selection diagnostic.
 *
 * @internal
 */
final readonly class ImpactSelection
{
    public function __construct(
        public TestSelection $selection,
        public bool $complete,
        public string $diagnostic,
    ) {}
}
