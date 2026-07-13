<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Reporting;

use Greenlight\Attribute\Test;
use Greenlight\Core\Event\EventTags;
use Greenlight\Expect\Expect;
use Greenlight\Reporting\JsonLinesReporter;
use JsonSchema\Validator;

/**
 * The shipped JSON Schema and the wire format must stay in lockstep: every
 * line the reporter emits for the canned stream validates against
 * resources/schema/jsonl-v1.schema.json, and the stream exercises every
 * registered event tag so no payload shape escapes the check.
 *
 * The schema must also have teeth: an unknown event tag and a corrupted
 * payload are rejected, so a vacuous always-true schema cannot pass.
 */
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

        Expect::that($violations)->toBe([])
            ->and(\array_keys($seenTags))->toEqualCanonicalizing(\array_keys(EventTags::all()));
    }

    #[Test]
    public function anUnknownEventTagIsRejected(): void
    {
        $decoded = \json_decode('{"v":1,"event":"bogus-event","data":{"occurredAt":1.5}}');
        $validator = new Validator();
        $validator->validate($decoded, $this->schema());

        Expect::that($validator->isValid())->toBeFalse();
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

        Expect::that($corrupted)->not()->toBeNull();

        $validator = new Validator();
        $validator->validate($corrupted, $this->schema());

        Expect::that($validator->isValid())->toBeFalse();
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

        return \implode('; ', $rendered);
    }
}
