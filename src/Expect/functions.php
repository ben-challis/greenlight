<?php

declare(strict_types=1);

namespace Greenlight;

use Greenlight\Expect\Expect;
use Greenlight\Expect\Expectation;
use Greenlight\Expect\ExpectationBuilder;
use Greenlight\Expect\OmittedExpectationValue;

/**
 * Creates a value expectation, or a call builder when the argument is absent.
 * The runner loads this file before configuration and test discovery.
 * Outside the runner, require this file after the Composer autoloader.
 *
 * @template T = OmittedExpectationValue
 *
 * @param T $value
 *
 * @return (T is OmittedExpectationValue ? ExpectationBuilder : Expectation<T>)
 */
function expect(mixed $value = OmittedExpectationValue::Value): ExpectationBuilder|Expectation
{
    if (\func_num_args() === 0) {
        return new ExpectationBuilder();
    }

    return Expect::value($value);
}
