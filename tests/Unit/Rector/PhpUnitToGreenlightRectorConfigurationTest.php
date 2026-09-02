<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Rector;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Rector\PhpUnitToGreenlightRector;

final class PhpUnitToGreenlightRectorConfigurationTest
{
    #[Test]
    public function unknownConfigurationKeysAreRejected(): void
    {
        $rector = new PhpUnitToGreenlightRector();

        Expect::that(static fn() => $rector->configure(['unknown' => true]))
            ->because('the Rector configuration MUST reject unknown keys')
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'Unknown configuration key "unknown". The only supported key is "drop_assertion_messages".',
            );
    }

    #[Test]
    public function dropAssertionMessagesRequiresABoolean(): void
    {
        $rector = new PhpUnitToGreenlightRector();

        Expect::that(static fn() => $rector->configure([
            PhpUnitToGreenlightRector::DROP_ASSERTION_MESSAGES => 'yes',
        ]))
            ->because('the Rector configuration MUST reject a non-boolean option value')
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'Configuration key "drop_assertion_messages" expects a boolean.',
            );
    }
}
