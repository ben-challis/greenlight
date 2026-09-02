<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Execution\ProcessPool\Protocol;

use Greenlight\Artifact\AttachmentKind;
use Greenlight\Artifact\AttachmentRetention;
use Greenlight\Artifact\StagedAttachment;
use Greenlight\Attribute\Test;
use Greenlight\Config\ArtifactConfiguration;
use Greenlight\Coverage\CoverageMap;
use Greenlight\Coverage\FileCoverage;
use Greenlight\Discovery\Plan\ExecutionPlan;
use Greenlight\Discovery\Plan\PlanEntry;
use Greenlight\Event\TestFinished;
use Greenlight\Execution\Artifact\ArtifactSession;
use Greenlight\Execution\ProcessPool\Protocol\JsonFrameCodec;
use Greenlight\Execution\ProcessPool\Protocol\Message;
use Greenlight\Execution\ProcessPool\Protocol\MessageRegistry;
use Greenlight\Execution\ProcessPool\Protocol\Messages\Assign;
use Greenlight\Execution\ProcessPool\Protocol\Messages\AttemptStarted;
use Greenlight\Execution\ProcessPool\Protocol\Messages\Bootstrap;
use Greenlight\Execution\ProcessPool\Protocol\Messages\Done;
use Greenlight\Execution\ProcessPool\Protocol\Messages\Drain;
use Greenlight\Execution\ProcessPool\Protocol\Messages\EventEnvelope;
use Greenlight\Execution\ProcessPool\Protocol\Messages\Fatal;
use Greenlight\Execution\ProcessPool\Protocol\Messages\Hello;
use Greenlight\Execution\ProcessPool\Protocol\Messages\Ready;
use Greenlight\Expect\Expect;
use Greenlight\IntegrationFixture\FixtureResource;
use Greenlight\IntegrationFixture\IntegrationResources;
use Greenlight\Internal\Event\EventCodec;
use Greenlight\Result\CapturedOutput;
use Greenlight\Result\Diagnostic;
use Greenlight\Result\DiagnosticSeverity;
use Greenlight\Result\FailureDetail;
use Greenlight\Result\Outcome;
use Greenlight\Result\OutcomeTransformation;
use Greenlight\Result\ResultPolicy;
use Greenlight\Result\ResultSummary;
use Greenlight\Result\SourceLocation;
use Greenlight\Result\TestResult;
use Greenlight\Result\ThrowableDetail;
use Greenlight\Test\DataProvider;
use Greenlight\Test\ExecutionPolicy;
use Greenlight\Test\RetryPolicy;
use Greenlight\Test\SchedulingPolicy;
use Greenlight\Test\SkipPolicy;
use Greenlight\Test\TestDefinition;
use Greenlight\Test\TestId;
use Greenlight\Tests\Unit\Reporting\CannedStream;
use JsonSchema\Validator;

final class WorkerProtocolSchemaTest
{
    #[Test]
    public function everyRegisteredMessageValidatesAgainstTheSchema(): void
    {
        $messages = $this->messages();
        $classes = \array_map(static fn(Message $message): string => $message::class, $messages);

        Expect::that($classes)
            ->because('each registered message MUST have a schema test value')
            ->toBe(MessageRegistry::all());
        Expect::that($this->messageSchemaTags())
            ->because('each registered message MUST have an explicit schema')
            ->toBe(\array_keys(MessageRegistry::all()));

        foreach ($messages as $tag => $message) {
            Expect::that($this->validationErrors($this->encodedEnvelope($message)))
                ->because(\sprintf('the "%s" message validates against the worker protocol schema', $tag))
                ->toBe([]);
        }
    }

    #[Test]
    public function everyRegisteredEventPayloadValidatesAgainstTheSchema(): void
    {
        $events = [];

        foreach (CannedStream::events() as $event) {
            $tagged = EventCodec::toTagged($event);
            $events[$tagged['event']] = $event;
        }

        Expect::that(\array_keys($events))
            ->because('each registered event MUST have a schema test value')
            ->toEqualCanonicalizing(\array_keys(EventCodec::tags()));
        Expect::that($this->eventSchemaTags())
            ->because('each registered event MUST have an explicit worker protocol schema')
            ->toBe(\array_keys(EventCodec::tags()));

        foreach ($events as $tag => $event) {
            Expect::that($this->validationErrors($this->encodedEnvelope(new EventEnvelope($event))))
                ->because(\sprintf('the "%s" event payload validates against the worker protocol schema', $tag))
                ->toBe([]);
        }
    }

    #[Test]
    public function compatiblePayloadsWithoutNewOptionalFieldsValidateAgainstTheSchema(): void
    {
        $bootstrapPayload = $this->withoutPaths($this->messages()['bootstrap']->toWire(), [
            ['policy'],
        ]);
        $assignPayload = $this->withoutPaths($this->messages()['assign']->toWire(), [
            ['artifactSession'],
            ['artifactConfiguration'],
            ['stopAfterFailures'],
            ['policy', 'failOnWarning'],
        ]);
        $eventPayload = $this->withoutPaths($this->messages()['event']->toWire(), [
            ['data', 'result', 'risky'],
            ['data', 'result', 'expectations'],
            ['data', 'result', 'attachments'],
        ]);

        Expect::that($this->validationErrors($this->asJsonObject(['v' => 1, 't' => 'bootstrap', 'p' => $bootstrapPayload])))
            ->because('the schema MUST accept compatible bootstrap payloads')
            ->toBe([]);
        Expect::that($this->validationErrors($this->asJsonObject(['v' => 1, 't' => 'assign', 'p' => $assignPayload])))
            ->because('the schema MUST accept compatible assignment payloads')
            ->toBe([]);
        Expect::that($this->validationErrors($this->asJsonObject(['v' => 1, 't' => 'event', 'p' => $eventPayload])))
            ->because('the schema MUST accept compatible test result payloads')
            ->toBe([]);
    }

    #[Test]
    public function anUnspecifiedMessageFieldIsRejected(): void
    {
        $payload = $this->messages()['assign']->toWire();
        $payload['futureProtocolField'] = true;

        Expect::that($this->validationErrors($this->asJsonObject(['v' => 1, 't' => 'assign', 'p' => $payload])))
            ->because('a protocol change MUST update the schema')
            ->not()
            ->toBe([]);
    }

    #[Test]
    public function emptyMapKeysAreRejected(): void
    {
        /** @var array{resources: array{fixtures: array<string, mixed>}} $bootstrapPayload */
        $bootstrapPayload = $this->messages()['bootstrap']->toWire();
        $bootstrapPayload['resources']['fixtures'][''] = $bootstrapPayload['resources']['fixtures']['postgres'];

        /** @var array{coverage: array{files: array<string, mixed>}} $donePayload */
        $donePayload = $this->messages()['done']->toWire();
        $donePayload['coverage']['files'][''] = [[1], []];

        Expect::that($this->validationErrors($this->asJsonObject(['v' => 1, 't' => 'bootstrap', 'p' => $bootstrapPayload])))
            ->because('the schema MUST reject an empty fixture ID')
            ->not()
            ->toBe([]);
        Expect::that($this->validationErrors($this->asJsonObject(['v' => 1, 't' => 'done', 'p' => $donePayload])))
            ->because('the schema MUST reject an empty coverage path')
            ->not()
            ->toBe([]);
    }

    #[Test]
    public function anExternalDataProviderWithoutAMethodIsRejected(): void
    {
        /** @var array{slice: array{entries: non-empty-list<array{definition: array{dataProvider: mixed}}>}} $payload */
        $payload = $this->messages()['assign']->toWire();
        $payload['slice']['entries'][0]['definition']['dataProvider'] = [
            'method' => null,
            'class' => 'App\SharedRows',
        ];

        Expect::that($this->validationErrors($this->asJsonObject(['v' => 1, 't' => 'assign', 'p' => $payload])))
            ->because('the schema MUST match the data-provider decoder contract')
            ->not()
            ->toBe([]);
    }

    /**
     * @return array<non-empty-string, Message>
     */
    private function messages(): array
    {
        $id = new TestId('App\ExampleTest', 'checksValue', 'example');
        $entry = new PlanEntry(
            new TestDefinition(
                'App\ExampleTest',
                'checksValue',
                ['unit'],
                new SkipPolicy(
                    condition: 'App\Conditions::available',
                    arguments: ['primary', 1, 2.5, true, null],
                ),
                new RetryPolicy(2, \RuntimeException::class),
                new DataProvider('examples', 'App\Examples'),
                new ExecutionPolicy(1.5, capture: true, noExpectations: false),
                new SchedulingPolicy(true, ['database.primary'], true),
            ),
            $id->dataSetKey,
        );
        $coverage = new CoverageMap([
            new FileCoverage('/project/src/Example.php', [2, 3], [5]),
        ]);
        $detail = new ThrowableDetail(
            \RuntimeException::class,
            'Example error.',
            '/project/tests/ExampleTest.php',
            12,
            ['App\ExampleTest::checksValue at /project/tests/ExampleTest.php:12'],
        );
        $result = new TestResult(
            $id,
            Outcome::Failed,
            0.125,
            -128,
            2,
            [new FailureDetail(
                'The values are not equal.',
                '1',
                '2',
                new SourceLocation('/project/tests/ExampleTest.php', 12),
            )],
            $detail,
            null,
            [new OutcomeTransformation('example policy', Outcome::Passed, Outcome::Failed)],
            new CapturedOutput(
                "example output\n",
                [new Diagnostic(DiagnosticSeverity::Warning, 'Example warning.', '/project/tests/ExampleTest.php', 12)],
                stdoutTruncated: true,
            ),
            risky: true,
            expectations: 3,
            attachments: [new StagedAttachment(
                'response',
                AttachmentKind::Text,
                'text/plain',
                7,
                \str_repeat('a', 64),
                2,
                'response.txt',
                AttachmentRetention::Always,
                'worker-1/response',
            )],
        );

        return [
            'hello' => new Hello('worker-1', 'run-token', 4242),
            'bootstrap' => new Bootstrap(
                2,
                '/project/greenlight.php',
                new IntegrationResources([
                    'postgres' => FixtureResource::from(
                        [
                            'database' => 'greenlight',
                            'port' => 5432,
                            'tls' => true,
                            'replicas' => ['primary', 'secondary'],
                            'options' => ['timeout' => 2.5],
                        ],
                        ['password' => 'test-secret'],
                    ),
                ]),
                policy: new ResultPolicy(
                    failOnDeprecation: true,
                    failOnNotice: true,
                    failOnWarning: true,
                    ignoreDeprecations: ['known deprecation*'],
                    failOnRisky: true,
                ),
            ),
            'ready' => new Ready(),
            'assign' => new Assign(
                new ExecutionPlan([$entry], 17),
                ['/project/src'],
                'pcov',
                true,
                new ResultPolicy(
                    failOnDeprecation: true,
                    failOnNotice: true,
                    failOnWarning: true,
                    ignoreDeprecations: ['known deprecation*'],
                    failOnRisky: true,
                ),
                new ArtifactSession('/tmp/greenlight-staging', 'build/greenlight-artifacts/run-1'),
                new ArtifactConfiguration(maxRunAttachments: 100),
                stopAfterFailures: 3,
            ),
            'drain' => new Drain(),
            'event' => new EventEnvelope(new TestFinished($result, 1_780_000_000.5)),
            'attempt-started' => new AttemptStarted($id, 2),
            'done' => new Done(
                new ResultSummary(passed: 2, failed: 1),
                123_456,
                $coverage,
                [$id],
            ),
            'fatal' => new Fatal($detail),
        ];
    }

    private function encodedEnvelope(Message $message): object
    {
        $frame = new JsonFrameCodec()->encode(MessageRegistry::envelope($message));
        $envelope = \json_decode(\substr($frame, 4), flags: \JSON_THROW_ON_ERROR);

        if (!\is_object($envelope)) {
            throw new \LogicException('A worker protocol envelope MUST be a JSON object.');
        }

        return $envelope;
    }

    /**
     * @param array<string, mixed> $envelope
     */
    private function asJsonObject(array $envelope): object
    {
        $decoded = \json_decode(\json_encode($envelope, \JSON_THROW_ON_ERROR), flags: \JSON_THROW_ON_ERROR);

        if (!\is_object($decoded)) {
            throw new \LogicException('A worker protocol envelope MUST be a JSON object.');
        }

        return $decoded;
    }

    /**
     * @return list<string>
     */
    private function validationErrors(object $envelope): array
    {
        $validator = new Validator();
        $validator->validate($envelope, $this->schema());
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

        return $errors;
    }

    private function schema(): object
    {
        return (object) ['$ref' => 'file://' . $this->schemaPath()];
    }

    /**
     * @return list<non-empty-string>
     */
    private function messageSchemaTags(): array
    {
        $schema = $this->schemaDocument();

        return $this->tagsFromVariants($schema['oneOf']);
    }

    /**
     * @return list<non-empty-string>
     */
    private function eventSchemaTags(): array
    {
        $schema = $this->schemaDocument();
        $definitions = $schema['definitions'] ?? null;

        if (!\is_array($definitions)) {
            throw new \LogicException('The worker protocol schema MUST contain definitions.');
        }

        $eventEnvelope = $definitions['eventEnvelope'] ?? null;

        if (!\is_array($eventEnvelope)) {
            throw new \LogicException('The worker protocol schema MUST define the event envelope.');
        }

        return $this->tagsFromVariants($eventEnvelope['oneOf'] ?? null, 'event');
    }

    /**
     * @return list<non-empty-string>
     */
    private function tagsFromVariants(mixed $variants, string $property = 't'): array
    {
        if (!\is_array($variants) || !\array_is_list($variants)) {
            throw new \LogicException('Protocol schema variants MUST be a list.');
        }

        $tags = [];

        foreach ($variants as $variant) {
            if (!\is_array($variant)) {
                throw new \LogicException('Each protocol schema variant MUST be an object.');
            }

            $properties = $variant['properties'] ?? null;
            $tagSchema = \is_array($properties) ? ($properties[$property] ?? null) : null;
            $values = \is_array($tagSchema) ? ($tagSchema['enum'] ?? null) : null;
            $tag = \is_array($values) ? ($values[0] ?? null) : null;

            if (!\is_string($tag) || $tag === '') {
                throw new \LogicException('Protocol schema tags MUST be nonempty strings.');
            }

            $tags[] = $tag;
        }

        return $tags;
    }

    /**
     * @return array<mixed>
     */
    private function schemaDocument(): array
    {
        $contents = \file_get_contents($this->schemaPath());

        if ($contents === false) {
            throw new \LogicException('Greenlight cannot read the worker protocol schema.');
        }

        $schema = \json_decode($contents, true, flags: \JSON_THROW_ON_ERROR);

        if (!\is_array($schema) || \array_is_list($schema)) {
            throw new \LogicException('The worker protocol schema MUST be a JSON object.');
        }

        return $schema;
    }

    private function schemaPath(): string
    {
        return \dirname(__DIR__, 5) . '/resources/schema/worker-protocol-v1.schema.json';
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<non-empty-list<array-key>> $paths
     *
     * @return array<string, mixed>
     */
    private function withoutPaths(array $payload, array $paths): array
    {
        foreach ($paths as $path) {
            $payload = $this->withoutPath($payload, $path);
        }

        return $payload;
    }

    /**
     * @template TKey of array-key
     *
     * @param array<TKey, mixed> $payload
     * @param non-empty-list<array-key> $path
     *
     * @return array<TKey, mixed>
     */
    private function withoutPath(array $payload, array $path): array
    {
        $key = \array_shift($path);

        if ($path === []) {
            unset($payload[$key]);

            return $payload;
        }

        $child = $payload[$key] ?? null;

        if (\is_array($child)) {
            $payload[$key] = $this->withoutPath($child, $path);
        }

        return $payload;
    }
}
