<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Expect;

/**
 * Represents a temporary probe failure that a temporal expectation can retry.
 *
 * @internal
 */
final class TransientProbeFailure extends \RuntimeException {}
