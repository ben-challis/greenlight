<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Coverage;

use Greenlight\Attribute\Test;
use Greenlight\Coverage\CoverageMap;
use Greenlight\Coverage\Export\CoberturaExporter;
use Greenlight\Coverage\FileCoverage;
use Greenlight\Expect\Expect;

final class CoberturaExporterTest
{
    #[Test]
    public function documentCarriesMetricsForEveryFile(): void
    {
        $map = new CoverageMap([
            new FileCoverage('/src/A.php', [3, 7], [5]),
            new FileCoverage('/src/B.php', [2], []),
        ]);

        $xml = new \SimpleXMLElement(new CoberturaExporter(1234)->export($map)[CoberturaExporter::FILE_NAME]);

        Expect::that($this->structure($xml))
            ->because('document carries metrics for every file')
            ->toBe([
                'attributes' => [
                    'line-rate' => '0.7500',
                    'branch-rate' => '0',
                    'lines-covered' => '3',
                    'lines-valid' => '4',
                    'branches-covered' => '0',
                    'branches-valid' => '0',
                    'complexity' => '0',
                    'version' => '0',
                    'timestamp' => '1234',
                ],
                'sources' => ['/'],
                'packages' => [[
                    'attributes' => [
                        'name' => 'greenlight',
                        'line-rate' => '0.7500',
                        'branch-rate' => '0',
                        'complexity' => '0',
                    ],
                    'classes' => [
                        [
                            'attributes' => [
                                'name' => 'src/A.php',
                                'filename' => 'src/A.php',
                                'line-rate' => '0.6667',
                                'branch-rate' => '0',
                                'complexity' => '0',
                            ],
                            'methods' => [[]],
                            'lines' => [
                                ['number' => '3', 'hits' => '1'],
                                ['number' => '5', 'hits' => '0'],
                                ['number' => '7', 'hits' => '1'],
                            ],
                        ],
                        [
                            'attributes' => [
                                'name' => 'src/B.php',
                                'filename' => 'src/B.php',
                                'line-rate' => '1.0000',
                                'branch-rate' => '0',
                                'complexity' => '0',
                            ],
                            'methods' => [[]],
                            'lines' => [
                                ['number' => '2', 'hits' => '1'],
                            ],
                        ],
                    ],
                ]],
            ]);
    }

    #[Test]
    public function emptyMapStillProducesAParsableDocument(): void
    {
        $xml = new \SimpleXMLElement(new CoberturaExporter()->export(CoverageMap::empty())[CoberturaExporter::FILE_NAME]);

        Expect::that($this->structure($xml))
            ->because('empty map still produces a parsable document')
            ->toBe([
                'attributes' => [
                    'line-rate' => '1.0000',
                    'branch-rate' => '0',
                    'lines-covered' => '0',
                    'lines-valid' => '0',
                    'branches-covered' => '0',
                    'branches-valid' => '0',
                    'complexity' => '0',
                    'version' => '0',
                    'timestamp' => '0',
                ],
                'sources' => ['/'],
                'packages' => [[
                    'attributes' => [
                        'name' => 'greenlight',
                        'line-rate' => '1.0000',
                        'branch-rate' => '0',
                        'complexity' => '0',
                    ],
                    'classes' => [],
                ]],
            ]);
    }

    /**
     * @return array{
     *     attributes: array<string, string>,
     *     sources: list<string>,
     *     packages: list<array{
     *         attributes: array<string, string>,
     *         classes: list<array{
     *             attributes: array<string, string>,
     *             methods: list<array<string, string>>,
     *             lines: list<array<string, string>>
     *         }>
     *     }>
     * }
     */
    private function structure(\SimpleXMLElement $xml): array
    {
        $sources = [];

        foreach ($this->xpath($xml, '/coverage/sources/source') as $source) {
            $sources[] = (string) $source;
        }

        $packages = [];

        foreach ($this->xpath($xml, '/coverage/packages/package') as $package) {
            $classes = [];

            foreach ($this->xpath($package, 'classes/class') as $class) {
                $classes[] = [
                    'attributes' => $this->attributes($class),
                    'methods' => $this->attributeSets($this->xpath($class, 'methods')),
                    'lines' => $this->attributeSets($this->xpath($class, 'lines/line')),
                ];
            }

            $packages[] = [
                'attributes' => $this->attributes($package),
                'classes' => $classes,
            ];
        }

        return [
            'attributes' => $this->attributes($xml),
            'sources' => $sources,
            'packages' => $packages,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function attributes(\SimpleXMLElement $element): array
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
    private function attributeSets(array $elements): array
    {
        return \array_map($this->attributes(...), $elements);
    }

    /**
     * @return list<\SimpleXMLElement>
     */
    private function xpath(\SimpleXMLElement $xml, string $expression): array
    {
        $nodes = $xml->xpath($expression);

        if (!\is_array($nodes)) {
            throw new \RuntimeException(\sprintf('XPath query "%s" failed.', $expression));
        }

        return \array_values($nodes);
    }
}
