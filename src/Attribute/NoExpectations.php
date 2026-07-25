<?php

declare(strict_types=1);

namespace Greenlight\Attribute;

/** Exempts an intentional zero-expectation test from risky-test detection. */
#[\Attribute(\Attribute::TARGET_METHOD)]
final readonly class NoExpectations {}
