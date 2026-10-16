<?php

declare(strict_types=1);

namespace Greenlight\Event;

/**
 * Defines an event that can occur in the run lifecycle.
 *
 * Each implementation declares a public `occurredAt` property. PHP 8.3 does
 * not support interface properties, so this contract uses a PHPDoc tag.
 *
 * @property-read float $occurredAt Unix timestamp with microsecond precision.
 */
interface Event {}
