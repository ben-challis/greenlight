<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Support;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Support\SimpleXml;

final class SimpleXmlTest
{
    #[Test]
    public function attributesReturnsAnEmptyMapWhenTheElementHasNoAttributes(): void
    {
        $element = \simplexml_load_string('<element/>');

        Expect::that($element)->toBeInstanceOf(\SimpleXMLElement::class);
        Expect::that(SimpleXml::attributes($element))->toBe([]);
    }
}
