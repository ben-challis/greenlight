<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Reporting;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Internal\Event\EventCodec;
use Greenlight\Reporting\JsonLinesReporter;
use JsonSchema\Validator;

final class JsonlSchemaTest
{
    #[Test]
    public function everyCannedLineValidatesAgainstTheShippedSchema(): void
    {
        $output = new BufferOutput();
        CannedStream::feed(new JsonLinesReporter($output));

        $seenTags = [];
        $violations = [];

        foreach (\explode("\n", \rtrim($output->buffer(), "\n")) as $line) {
            $decoded = \json_decode($line, flags: \JSON_THROW_ON_ERROR);
            $validator = new Validator();
            $validator->validate($decoded, $this->schema());

            if (!$validator->isValid()) {
                $violations[] = \sprintf('%s: %s', $line, $this->renderErrors($validator));
            }

            $tag = $this->eventTag($line);

            if ($tag !== null) {
                $seenTags[$tag] = true;
            }
        }

        Expect::that($violations)->because('every canned line validates against the shipped schema')->toBe([]);
        Expect::that(\array_keys($seenTags))->toEqualCanonicalizing(\array_keys(EventCodec::tags()));
    }

    #[Test]
    public function anUnknownEventTagIsRejected(): void
    {
        $decoded = \json_decode('{"v":1,"event":"bogus-event","data":{"occurredAt":1.5}}');
        $validator = new Validator();
        $validator->validate($decoded, $this->schema());

        Expect::that($validator->isValid())->because('an unknown event tag is rejected')->toBeFalse();
    }

    #[Test]
    public function aCorruptedOutcomeIsRejected(): void
    {
        $output = new BufferOutput();
        CannedStream::feed(new JsonLinesReporter($output));

        $corrupted = null;

        foreach (\explode("\n", \rtrim($output->buffer(), "\n")) as $line) {
            if ($this->eventTag($line) !== 'test-finished') {
                continue;
            }

            $decoded = \json_decode($line, true, flags: \JSON_THROW_ON_ERROR);

            if (!\is_array($decoded) || !\is_array($decoded['data']) || !\is_array($decoded['data']['result'])) {
                continue;
            }

            $decoded['data']['result']['outcome'] = 'exploded';
            $corrupted = \json_decode(\json_encode($decoded, \JSON_THROW_ON_ERROR));

            break;
        }

        Expect::that($corrupted)->because('a corrupted outcome is rejected')->not()->toBeNull();

        $validator = new Validator();
        $validator->validate($corrupted, $this->schema());

        Expect::that($validator->isValid())->because('a corrupted outcome is rejected')->toBeFalse();
    }

    #[Test]
    public function aNonPositiveDiagnosticLineIsRejected(): void
    {
        $output = new BufferOutput();
        CannedStream::feed(new JsonLinesReporter($output));
        $corrupted = null;

        foreach (\explode("\n", \rtrim($output->buffer(), "\n")) as $line) {
            if ($this->eventTag($line) !== 'test-finished') {
                continue;
            }

            $decoded = \json_decode($line, true, flags: \JSON_THROW_ON_ERROR);

            if (!\is_array($decoded) || !\is_array($decoded['data']) || !\is_array($decoded['data']['result'])) {
                continue;
            }

            $decoded['data']['result']['output'] = [
                'stdout' => '',
                'diagnostics' => [[
                    'severity' => 'warning',
                    'message' => 'Warning.',
                    'file' => '/tests/ProbeTest.php',
                    'line' => 0,
                ]],
                'stdoutTruncated' => false,
                'diagnosticsTruncated' => false,
            ];
            $corrupted = \json_decode(\json_encode($decoded, \JSON_THROW_ON_ERROR));

            break;
        }

        Expect::that($corrupted)->because('a diagnostic line MUST be positive')->not()->toBeNull();

        $validator = new Validator();
        $validator->validate($corrupted, $this->schema());

        Expect::that($validator->isValid())->because('a diagnostic line MUST be positive')->toBeFalse();
    }

    private function schema(): object
    {
        return (object) ['$ref' => 'file://' . \dirname(__DIR__, 3) . '/resources/schema/jsonl-v1.schema.json'];
    }

    private function eventTag(string $line): ?string
    {
        $decoded = \json_decode($line, true, flags: \JSON_THROW_ON_ERROR);

        if (!\is_array($decoded)) {
            return null;
        }

        $tag = $decoded['event'] ?? null;

        return \is_string($tag) ? $tag : null;
    }

    private function renderErrors(Validator $validator): string
    {
        $rendered = [];

        foreach ($validator->getErrors() as $error) {
            if (!\is_array($error)) {
                continue;
            }

            $property = $error['property'] ?? null;
            $message = $error['message'] ?? null;

            $rendered[] = \sprintf(
                '[%s] %s',
                \is_string($property) ? $property : '',
                \is_string($message) ? $message : '',
            );
        }

        return \implode("\n", $rendered);
    }
}
