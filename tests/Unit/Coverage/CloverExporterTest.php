<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Coverage;

use Greenlight\Attribute\Test;
use Greenlight\Coverage\CoverageMap;
use Greenlight\Coverage\Export\CloverExporter;
use Greenlight\Coverage\FileCoverage;
use Greenlight\Expect\Expect;

final class CloverExporterTest
{
    #[Test]
    public function documentCarriesPerFileAndProjectStatementMetrics(): void
    {
        $map = new CoverageMap([
            new FileCoverage('/src/A.php', [3, 7], [5]),
            new FileCoverage('/src/B.php', [2], []),
        ]);

        $xml = new \SimpleXMLElement(new CloverExporter(1234)->export($map)[CloverExporter::FILE_NAME]);

        Expect::that(self::structure($xml))
            ->because('document carries per file and project statement metrics')
            ->toBe([
                'generated' => '1234',
                'projects' => [[
                    'timestamp' => '1234',
                    'name' => 'greenlight',
                    'files' => [
                        [
                            'path' => '/src/A.php',
                            'lines' => [
                                ['num' => '3', 'type' => 'stmt', 'count' => '1'],
                                ['num' => '5', 'type' => 'stmt', 'count' => '0'],
                                ['num' => '7', 'type' => 'stmt', 'count' => '1'],
                            ],
                            'metrics' => [[
                                'loc' => '0',
                                'ncloc' => '0',
                                'classes' => '0',
                                'methods' => '0',
                                'coveredmethods' => '0',
                                'conditionals' => '0',
                                'coveredconditionals' => '0',
                                'statements' => '3',
                                'coveredstatements' => '2',
                                'elements' => '3',
                                'coveredelements' => '2',
                            ]],
                        ],
                        [
                            'path' => '/src/B.php',
                            'lines' => [
                                ['num' => '2', 'type' => 'stmt', 'count' => '1'],
                            ],
                            'metrics' => [[
                                'loc' => '0',
                                'ncloc' => '0',
                                'classes' => '0',
                                'methods' => '0',
                                'coveredmethods' => '0',
                                'conditionals' => '0',
                                'coveredconditionals' => '0',
                                'statements' => '1',
                                'coveredstatements' => '1',
                                'elements' => '1',
                                'coveredelements' => '1',
                            ]],
                        ],
                    ],
                    'metrics' => [[
                        'files' => '2',
                        'loc' => '0',
                        'ncloc' => '0',
                        'classes' => '0',
                        'methods' => '0',
                        'coveredmethods' => '0',
                        'conditionals' => '0',
                        'coveredconditionals' => '0',
                        'statements' => '4',
                        'coveredstatements' => '3',
                        'elements' => '4',
                        'coveredelements' => '3',
                    ]],
                ]],
            ]);
    }

    #[Test]
    public function emptyMapStillProducesAParsableDocument(): void
    {
        $xml = new \SimpleXMLElement(new CloverExporter()->export(CoverageMap::empty())[CloverExporter::FILE_NAME]);

        Expect::that(self::structure($xml))
            ->because('empty map still produces a parsable document')
            ->toBe([
                'generated' => '0',
                'projects' => [[
                    'timestamp' => '0',
                    'name' => 'greenlight',
                    'files' => [],
                    'metrics' => [[
                        'files' => '0',
                        'loc' => '0',
                        'ncloc' => '0',
                        'classes' => '0',
                        'methods' => '0',
                        'coveredmethods' => '0',
                        'conditionals' => '0',
                        'coveredconditionals' => '0',
                        'statements' => '0',
                        'coveredstatements' => '0',
                        'elements' => '0',
                        'coveredelements' => '0',
                    ]],
                ]],
            ]);
    }

    /**
     * @return array{
     *     generated: string,
     *     projects: list<array{
     *         timestamp: string,
     *         name: string,
     *         files: list<array{
     *             path: string,
     *             lines: list<array<string, string>>,
     *             metrics: list<array<string, string>>
     *         }>,
     *         metrics: list<array<string, string>>
     *     }>
     * }
     */
    private static function structure(\SimpleXMLElement $xml): array
    {
        $projects = [];

        foreach (self::xpath($xml, '/coverage/project') as $project) {
            $files = [];

            foreach (self::xpath($project, 'file') as $file) {
                $files[] = [
                    'path' => (string) $file['name'],
                    'lines' => self::attributeSets(self::xpath($file, 'line')),
                    'metrics' => self::attributeSets(self::xpath($file, 'metrics')),
                ];
            }

            $projects[] = [
                'timestamp' => (string) $project['timestamp'],
                'name' => (string) $project['name'],
                'files' => $files,
                'metrics' => self::attributeSets(self::xpath($project, 'metrics')),
            ];
        }

        return [
            'generated' => (string) $xml['generated'],
            'projects' => $projects,
        ];
    }

    /**
     * @param list<\SimpleXMLElement> $elements
     *
     * @return list<array<string, string>>
     */
    private static function attributeSets(array $elements): array
    {
        $sets = [];

        foreach ($elements as $element) {
            $attributes = [];

            foreach ($element->attributes() as $name => $value) {
                $attributes[(string) $name] = (string) $value;
            }

            $sets[] = $attributes;
        }

        return $sets;
    }

    /**
     * @return list<\SimpleXMLElement>
     */
    private static function xpath(\SimpleXMLElement $xml, string $expression): array
    {
        $nodes = $xml->xpath($expression);

        if (!\is_array($nodes)) {
            throw new \RuntimeException(\sprintf('XPath query "%s" failed.', $expression));
        }

        return \array_values($nodes);
    }
}
