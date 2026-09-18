<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\IntegrationFixture;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\IntegrationFixture\FixtureResource;
use Greenlight\IntegrationFixture\IntegrationResources;
use Greenlight\Tests\Support\MemoryStream;

final class IntegrationResourcesTest
{
    #[Test]
    public function resourceValuesAreTypedAndSecretsNeedExplicitReveal(): void
    {
        $resource = FixtureResource::from(
            values: [
                'host' => '127.0.0.1',
                'port' => 5432,
                'tls' => true,
                'ratio' => 1.5,
                'tags' => ['test', 'database'],
                'options' => ['timeout' => 3],
            ],
            secrets: ['password' => 'do-not-print'],
        );

        Expect::value($resource->string('host'))->toBe('127.0.0.1');
        Expect::value($resource->int('port'))->toBe(5432);
        Expect::value($resource->bool('tls'))->toBeTrue();
        Expect::value($resource->float('ratio'))->toBe(1.5);
        Expect::value($resource->list('tags'))->toBe(['test', 'database']);
        Expect::value($resource->map('options'))->toBe(['timeout' => 3]);
        Expect::value($resource->secret('password')->reveal())->toBe('do-not-print');
        Expect::value($resource->__debugInfo()['secrets'])->toBe(['password' => '[redacted]']);
        Expect::value(\var_export($resource, true))->not()->toContain('do-not-print');
        Expect::value(\var_export($resource->secret('password'), true))->not()->toContain('do-not-print');
    }

    #[Test]
    public function resourcesSurviveTheWireRoundTripWithoutExposingOtherFixtures(): void
    {
        $resources = new IntegrationResources([
            'postgres' => FixtureResource::from(
                ['host' => 'db', 'port' => 5432],
                ['password' => 'secret'],
            ),
        ]);

        $restored = IntegrationResources::fromWire($resources->toWire());
        $postgres = $restored->fixture('postgres');

        Expect::value($postgres->string('host'))->toBe('db');
        Expect::value($postgres->int('port'))->toBe(5432);
        Expect::value($postgres->secret('password')->reveal())->toBe('secret');
        Expect::value($restored->has('redis'))->toBeFalse();
    }

    #[Test]
    public function channelValuesOverrideSharedValues(): void
    {
        $shared = FixtureResource::from(
            ['host' => 'db', 'database' => 'shared'],
            ['password' => 'shared-secret'],
        );
        $channel = FixtureResource::from(
            ['database' => 'channel_2'],
            ['password' => 'channel-secret'],
        );
        $merged = $shared->mergedWith($channel);

        Expect::value($merged->string('host'))->toBe('db');
        Expect::value($merged->string('database'))->toBe('channel_2');
        Expect::value($merged->secret('password')->reveal())->toBe('channel-secret');
    }

    #[Test]
    public function debugRepresentationsKeepNestedSecretsRedacted(): void
    {
        $secret = 'database-password';
        $resource = FixtureResource::from(secrets: ['password' => $secret]);
        $resources = new IntegrationResources(['database' => $resource]);
        $debug = $resources->__debugInfo();

        \ob_start();
        \var_dump($resources);
        $dump = \ob_get_clean();
        $export = \var_export($resources, true);

        Expect::value(($debug['fixtures']['database'] ?? null) === $resource)
            ->because('integration resource debug information MUST retain its fixture map')
            ->toBe(true);
        Expect::value(\is_string($dump) && \str_contains($dump, 'database'))
            ->because('integration resource dumps MUST identify their fixture IDs')
            ->toBe(true);
        Expect::value(\is_string($dump) && \str_contains($dump, $secret))
            ->because('integration resource dumps MUST NOT disclose nested secrets')
            ->toBe(false);
        Expect::value(\str_contains($export, $secret))
            ->because('integration resource exports MUST NOT disclose nested secrets')
            ->toBe(false);
    }

    #[Test]
    public function resourcesRejectInvalidFixtureMaps(): void
    {
        Expect::calling(static fn(): IntegrationResources => new IntegrationResources([
            "\xB1\x31" => FixtureResource::empty(),
        ]))->toThrow(\InvalidArgumentException::class, matching: '/non-empty UTF-8 fixture IDs/');
    }

    #[Test]
    public function nonJsonValuesAreRejectedBeforeTransport(): void
    {
        $stream = MemoryStream::open();

        try {
            Expect::calling(static fn(): FixtureResource => FixtureResource::from(['stream' => $stream]))
                ->toThrow(\InvalidArgumentException::class, matching: '/JSON-safe/');
        } finally {
            MemoryStream::close($stream);
        }

        Expect::calling(static fn(): FixtureResource => FixtureResource::from(['number' => \INF]))
            ->toThrow(\InvalidArgumentException::class, matching: '/finite numbers/');
        Expect::calling(static fn(): FixtureResource => FixtureResource::from(['text' => "\xB1\x31"]))
            ->toThrow(\InvalidArgumentException::class, matching: '/UTF-8/');
        Expect::calling(static fn(): FixtureResource => FixtureResource::from(secrets: ['token' => "\xB1\x31"]))
            ->toThrow(\InvalidArgumentException::class, matching: '/UTF-8/');
    }

    /**
     * @param array<mixed> $secrets
     */
    #[Test]
    #[DataSet('invalidSecretTypes')]
    public function secretMapsRejectInvalidRuntimeTypes(array $secrets): void
    {
        Expect::calling(static fn(): FixtureResource => FixtureResource::from(
            secrets: $secrets,
        ))
            ->because('fixture secret maps MUST reject invalid runtime types at their boundary')
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'Fixture secrets must be a map of non-empty UTF-8 string keys to UTF-8 strings.',
            );
    }

    /**
     * @return iterable<string, array{array<mixed>}>
     */
    public static function invalidSecretTypes(): iterable
    {
        yield 'integer key' => [[0 => 'secret']];
        yield 'integer value' => [['token' => 123]];
    }

    /**
     * @param array<mixed> $values
     */
    #[Test]
    #[DataSet('invalidValueMapKeys')]
    public function valueMapsRejectInvalidRuntimeKeys(array $values): void
    {
        $from = new \ReflectionMethod(FixtureResource::class, 'from');

        Expect::calling(static fn(): mixed => $from->invoke(null, $values))
            ->because('fixture value maps MUST reject invalid runtime keys at their boundary')
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'Fixture resource maps need non-empty UTF-8 string keys.',
            );
    }

    /**
     * @return iterable<string, array{array<mixed>}>
     */
    public static function invalidValueMapKeys(): iterable
    {
        yield 'integer key' => [[0 => 'value']];
        yield 'empty key' => [['' => 'value']];
        yield 'invalid UTF-8 key' => [["\xB1\x31" => 'value']];
    }
}
