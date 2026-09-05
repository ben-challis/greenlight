<?php

declare(strict_types=1);

namespace Greenlight\Amp;

/**
 * Reports an unavailable Amp dependency or an invalid attempt context.
 *
 * @internal
 */
final class AmpBridgeError extends \RuntimeException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function runtimeUnavailable(): self
    {
        return new self('AmpPlugin requires amphp/amp ^3.1 and revolt/event-loop ^1.0. Install these packages before you activate the plugin.');
    }

    public static function contextUnavailable(): self
    {
        return new self('AmpContext requires an active AmpPlugin attempt. Create child work with AmpContext::async() or pass a cancellation token explicitly.');
    }

    public static function overlappingAttempts(): self
    {
        return new self('AmpPlugin cannot run overlapping test attempts. Complete the active attempt before you start another attempt.');
    }

    public static function bodyClosed(): self
    {
        return new self('AmpContext::async() cannot create child work after the test body ends. Complete child work before cleanup.');
    }

}
