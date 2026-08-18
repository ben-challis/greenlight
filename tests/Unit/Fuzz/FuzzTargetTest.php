<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Fuzz;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Runner\Protocol\MessageRegistry;

final class FuzzTargetTest
{
    #[Test]
    public function jsonFrameTargetReplaysItsSeedCorpus(): void
    {
        $this->replayCorpus('json-frame');
    }

    #[Test]
    public function protocolMessageTargetReplaysItsSeedCorpus(): void
    {
        $this->replayCorpus('protocol-message');
    }

    #[Test]
    public function frameStreamTargetReplaysItsSeedCorpus(): void
    {
        $this->replayCorpus('frame-stream');
    }

    #[Test]
    public function wireMessageTargetReplaysItsSeedCorpus(): void
    {
        $this->replayCorpus('wire-message');
    }

    #[Test]
    public function discoveryCacheEntryTargetReplaysItsSeedCorpus(): void
    {
        $this->replayCorpus('discovery-cache-entry');
    }

    #[Test]
    public function artifactSidecarTargetReplaysItsSeedCorpus(): void
    {
        $this->replayCorpus('artifact-sidecar');
    }

    #[Test]
    public function protocolCorpusCoversEveryMessageType(): void
    {
        $seedPaths = \glob(__DIR__ . '/../../../tools/fuzz/corpus/protocol-message/*.json');

        if ($seedPaths === false) {
            Fail::because('Expected PHP to read the protocol seed paths.');
        }

        $tags = [];

        foreach ($seedPaths as $seedPath) {
            $decoded = \json_decode((string) \file_get_contents($seedPath), true);

            $envelope = $this->map($decoded);

            if ($envelope === null) {
                continue;
            }

            try {
                $tags[] = MessageRegistry::open($envelope)::tag();
            } catch (\Throwable) {
            }
        }

        \sort($tags);

        Expect::that(\array_values(\array_unique($tags)))
            ->because('the corpus MUST enter every protocol message decoder')
            ->toBe([
                'assign',
                'attempt-started',
                'bootstrap',
                'done',
                'drain',
                'event',
                'fatal',
                'hello',
                'ready',
                'recycling',
            ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function map(mixed $value): ?array
    {
        if (!\is_array($value) || ($value !== [] && \array_is_list($value))) {
            return null;
        }

        $map = [];

        foreach ($value as $key => $item) {
            if (!\is_string($key)) {
                return null;
            }

            $map[$key] = $item;
        }

        return $map;
    }

    private function replayCorpus(string $name): void
    {
        $configuration = new FuzzerConfigurationCapture();
        $config = $configuration;

        require __DIR__ . '/../../../tools/fuzz/' . $name . '.php';

        Expect::that($configuration->allowedExceptions)
            ->because('the target MUST report each uncaught throwable as a crash')
            ->toBe([]);
        Expect::that($configuration->maxLen)
            ->because('the target MUST limit generated input size')
            ->toBe(64 * 1024);

        $seedPaths = \glob(__DIR__ . '/../../../tools/fuzz/corpus/' . $name . '/*');

        if ($seedPaths === false || $seedPaths === []) {
            Fail::because('Expected the fuzz target to have seed inputs.');
        }

        foreach ($seedPaths as $seedPath) {
            $input = \file_get_contents($seedPath);

            if ($input === false) {
                Fail::because('Expected PHP to read each fuzz seed.');
            }

            ($configuration->target)($input);
        }
    }
}

final class FuzzerConfigurationCapture
{
    /**
     * @var list<class-string<\Throwable>>
     */
    public array $allowedExceptions = [\Exception::class];

    public int $maxLen = \PHP_INT_MAX;

    public \Closure $target;

    /**
     * @param list<class-string<\Throwable>> $allowedExceptions
     */
    public function setAllowedExceptions(array $allowedExceptions): void
    {
        $this->allowedExceptions = $allowedExceptions;
    }

    public function setMaxLen(int $maxLen): void
    {
        $this->maxLen = $maxLen;
    }

    public function setTarget(\Closure $target): void
    {
        $this->target = $target;
    }
}
