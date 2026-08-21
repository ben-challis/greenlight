<?php

declare(strict_types=1);

namespace Greenlight\Harness;

/**
 * Identifies a resolver that handles every request. Greenlight places one
 * terminal resolver after all fallback-capable resolvers.
 *
 * A terminal resolver MUST return a resolved or failed result.
 */
interface TerminalServiceResolver extends ServiceResolver {}
