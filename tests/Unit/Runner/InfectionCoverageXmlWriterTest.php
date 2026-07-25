<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Fixture\TempDirectory;
use Greenlight\InfectionAdapter\CoverageXmlWriter;

final readonly class InfectionCoverageXmlWriterTest
{
    public function __construct(private TempDirectory $temporaryDirectory) {}

    #[Test]
    public function convertsTheStreamingArtifactToInfectionsCoverageXmlShape(): void
    {
        require_once \dirname(__DIR__, 3) . '/packages/infection-adapter/src/CoverageXmlWriter.php';

        $root = $this->temporaryDirectory->path();
        $source = $root . '/src/Subject.php';
        \mkdir(\dirname($source), 0o777, true);
        \file_put_contents($source, "<?php\nreturn true;\n");
        $artifact = $root . '/map.jsonl';
        $records = [
            ['v' => 1, 'type' => 'meta', 'root' => $root, 'runId' => 'run-1', 'complete' => true],
            ['v' => 1, 'type' => 'test', 'test' => 0, 'id' => ['class' => 'Example\SubjectTest', 'method' => 'works', 'dataSetKey' => null], 'renderedId' => 'Example\SubjectTest::works', 'file' => $root . '/tests/SubjectTest.php'],
            ['v' => 1, 'type' => 'coverage', 'test' => 0, 'file' => $source, 'lines' => [2]],
            ['v' => 1, 'type' => 'source', 'file' => $source, 'covered' => true, 'lines' => [2]],
            ['v' => 1, 'type' => 'source', 'file' => $source, 'covered' => false, 'lines' => [3]],
        ];
        \file_put_contents(
            $artifact,
            \implode("\n", \array_map(
                static fn(array $record): string => \json_encode($record, \JSON_THROW_ON_ERROR),
                $records,
            )) . "\n",
        );

        $target = $root . '/coverage-xml';
        new CoverageXmlWriter()->write($artifact, $target);

        $index = new \DOMDocument();
        $index->load($target . '/index.xml');
        $xpath = new \DOMXPath($index);
        $xpath->registerNamespace('c', 'https://schema.phpunit.de/coverage/1.0');
        $href = $xpath->evaluate('string(/c:phpunit/c:project/c:directory/c:file/@href)');

        if (!\is_string($href)) {
            Fail::because('Expected the Infection coverage index to contain a string href.');
        }

        Expect::that($href)->toBeString()->not()->toBe('');

        $file = new \DOMDocument();
        $file->load($target . '/' . $href);
        $xpath = new \DOMXPath($file);
        $xpath->registerNamespace('c', 'https://schema.phpunit.de/coverage/1.0');

        Expect::that($xpath->evaluate('string(/c:phpunit/c:file/@path)'))->toBe('src')
            ->and($xpath->evaluate('string(/c:phpunit/c:file/c:coverage/c:line/c:covered/@by)'))->toBe('Example\SubjectTest::works')
            ->and($xpath->evaluate('string(/c:phpunit/c:file/c:totals/c:lines/@executable)'))->toBe('2')
            ->and($xpath->evaluate('string(/c:phpunit/c:file/c:totals/c:lines/@executed)'))->toBe('1');
    }
}
