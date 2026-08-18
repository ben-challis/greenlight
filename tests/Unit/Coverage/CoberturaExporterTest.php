<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Coverage;

use Greenlight\Attribute\Test;
use Greenlight\Coverage\CoverageMap;
use Greenlight\Coverage\Export\CoberturaExporter;
use Greenlight\Coverage\FileCoverage;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Support\SimpleXml;

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

        foreach (SimpleXml::xpath($xml, '/coverage/sources/source') as $source) {
            $sources[] = (string) $source;
        }

        $packages = [];

        foreach (SimpleXml::xpath($xml, '/coverage/packages/package') as $package) {
            $classes = [];

            foreach (SimpleXml::xpath($package, 'classes/class') as $class) {
                $classes[] = [
                    'attributes' => SimpleXml::attributes($class),
                    'methods' => SimpleXml::attributeSets(SimpleXml::xpath($class, 'methods')),
                    'lines' => SimpleXml::attributeSets(SimpleXml::xpath($class, 'lines/line')),
                ];
            }

            $packages[] = [
                'attributes' => SimpleXml::attributes($package),
                'classes' => $classes,
            ];
        }

        return [
            'attributes' => SimpleXml::attributes($xml),
            'sources' => $sources,
            'packages' => $packages,
        ];
    }

}
