<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Doubles;

use Greenlight\Attribute\Test;
use Greenlight\Doubles\Doubles;
use Greenlight\Doubles\InvalidDoubleUsage;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Fixture\Doubles\ObjectDefault;

final readonly class ObjectDefaultTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function objectDefaultsAreRejectedBeforeGeneratingAnInvalidProxy(): void
    {
        $doubles = new Doubles($this->tempDirectory->path() . '/proxies');

        try {
            Expect::that(static fn(): object => $doubles->stub(ObjectDefault::class))
                ->because('an object default cannot be reproduced in generated proxy source')
                ->toThrow(
                    InvalidDoubleUsage::class,
                    message: 'Doubles cannot reproduce the object default of parameter $value from '
                        . ObjectDefault::class
                        . '::run() in a proxy. Use an interface without object defaults instead.',
                );
        } finally {
            $doubles->dispose();
        }
    }
}
