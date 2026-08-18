<?php

declare(strict_types=1);

namespace Greenlight\Tests\Support;

final class SimpleXml
{
    /**
     * @return array<string, string>
     */
    public static function attributes(\SimpleXMLElement $element): array
    {
        $attributes = [];

        foreach ($element->attributes() as $name => $value) {
            $attributes[$name] = (string) $value;
        }

        return $attributes;
    }

    /**
     * @param list<\SimpleXMLElement> $elements
     *
     * @return list<array<string, string>>
     */
    public static function attributeSets(array $elements): array
    {
        return \array_map(self::attributes(...), $elements);
    }

    /**
     * @return list<\SimpleXMLElement>
     */
    public static function xpath(\SimpleXMLElement $xml, string $expression): array
    {
        $nodes = $xml->xpath($expression);

        if (!\is_array($nodes)) {
            throw new \RuntimeException(\sprintf('XPath query "%s" failed.', $expression));
        }

        return \array_values($nodes);
    }

    private function __construct() {}
}
