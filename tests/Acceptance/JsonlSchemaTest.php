<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Support\AcceptanceProject;
use JsonSchema\Validator;

/**
 * Drives --reporter=jsonl end to end and validates every emitted line
 * against the shipped resources/schema/jsonl-v1.schema.json.
 *
 * The generated project mixes outcomes (pass, expectation failure, error,
 * skip), includes a data-set test for a non-null dataSetKey, and recycles
 * workers after every test so the stream carries every event tag a real run
 * can produce. Suite events are validated at unit level against the canned
 * stream; no run emits them today.
 *
 * Stdout only: extension noise on stderr (Xdebug, ddtrace) would interleave
 * non-JSON lines into the stream.
 */
final class JsonlSchemaTest
{
    private const array PRODUCIBLE_TAGS = [
        'run-started',
        'run-finished',
        'class-started',
        'class-finished',
        'test-started',
        'test-finished',
        'worker-spawned',
        'worker-recycled',
    ];

    #[Test]
    public function everyEmittedLineValidatesAgainstTheShippedSchema(): void
    {
        $project = $this->writeProject();

        try {
            [$exit, $lines] = $project->runLinesStdout('run', '--reporter=jsonl');
            Expect::that($exit)->toBe(1);

            $schema = (object) ['$ref' => 'file://' . \dirname(__DIR__, 2) . '/resources/schema/jsonl-v1.schema.json'];
            $seenTags = [];
            $violations = [];

            Expect::that($lines)->not()->toBeEmpty();

            foreach ($lines as $line) {
                $decoded = \json_decode($line, flags: \JSON_THROW_ON_ERROR);
                $validator = new Validator();
                $validator->validate($decoded, $schema);

                if (!$validator->isValid()) {
                    $errors = [];

                    foreach ($validator->getErrors() as $error) {
                        if (!\is_array($error)) {
                            continue;
                        }

                        $property = $error['property'] ?? null;
                        $message = $error['message'] ?? null;

                        $errors[] = \sprintf(
                            '[%s] %s',
                            \is_string($property) ? $property : '',
                            \is_string($message) ? $message : '',
                        );
                    }

                    $violations[] = \sprintf('%s: %s', $line, \implode('; ', $errors));
                }

                $assoc = \json_decode($line, true, flags: \JSON_THROW_ON_ERROR);

                if (\is_array($assoc) && \is_string($assoc['event'] ?? null)) {
                    $seenTags[$assoc['event']] = true;
                }
            }

            Expect::that($violations)->toBe([]);

            foreach (self::PRODUCIBLE_TAGS as $tag) {
                Expect::that($seenTags)->toHaveKey($tag);
            }
        } finally {
            $project->remove();
        }
    }

    private function writeProject(): AcceptanceProject
    {
        $project = AcceptanceProject::create('jsonl-schema');

        $project->write('tests/MixedOutcomesProbeTest.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace JsonlSchemaProbe;

            use Greenlight\Attribute\DataRow;
            use Greenlight\Attribute\Skip;
            use Greenlight\Attribute\Test;
            use Greenlight\Expect\Expect;

            final class MixedOutcomesProbeTest
            {
                #[Test]
                public function passes(): void
                {
                    Expect::that(true)->toBeTrue();
                }

                #[Test]
                public function failsAnExpectation(): void
                {
                    Expect::that(1 + 1)->toBe(3);
                }

                #[Test]
                public function errors(): never
                {
                    throw new \RuntimeException('intentional jsonl schema probe error');
                }

                #[Test]
                #[Skip('intentional jsonl schema probe skip')]
                public function skips(): void
                {
                }

                #[Test]
                #[DataRow(['one'])]
                #[DataRow(['two'])]
                public function acceptsRows(string $row): void
                {
                    Expect::that($row)->toBeString();
                }
            }
            PHP);

        // recycleAfterTests: 1 forces worker-recycled events into the stream.
        $project->write('greenlight.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Config\GreenlightConfig;

            require_once __DIR__ . '/tests/MixedOutcomesProbeTest.php';

            return GreenlightConfig::create()
                ->paths([__DIR__ . '/tests'])
                ->workers(2, recycleAfterTests: 1);

            PHP);

        return $project;
    }
}
