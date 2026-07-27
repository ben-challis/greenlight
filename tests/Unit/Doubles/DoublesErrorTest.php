<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Doubles;

use Greenlight\Attribute\Test;
use Greenlight\Doubles\DoublesError;
use Greenlight\Expect\Expect;

final class DoublesErrorTest
{
    #[Test]
    public function defaultValueErrorsNameTheParameterAndMethod(): void
    {
        Expect::that(DoublesError::defaultValueNotReproducible('limit', 'Example', 'run')->getMessage())
            ->toBe('Doubles cannot reproduce the default value of parameter $limit from Example::run() in a proxy.');

        Expect::that(DoublesError::objectDefaultNotReproducible('clock', 'Example', 'run')->getMessage())
            ->toBe(
                'Doubles cannot reproduce the object default of parameter $clock from Example::run() in a proxy. '
                . 'Use an interface without object defaults instead.',
            );
    }
}
