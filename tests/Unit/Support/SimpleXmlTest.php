<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Support;

use Greenlight\Attribute\Test;
use Greenlight\Tests\Support\SimpleXml;

use function Greenlight\expect;

final class SimpleXmlTest
{
    #[Test]
    public function attributesReturnsAnEmptyMapWhenTheElementHasNoAttributes(): void
    {
        $element = \simplexml_load_string('<element/>');

        expect($element)->toBeInstanceOf(\SimpleXMLElement::class);
        expect(SimpleXml::attributes($element))->toBe([]);
    }
}
