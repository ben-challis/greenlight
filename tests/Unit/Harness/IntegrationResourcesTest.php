<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Harness;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Harness\FixtureResource;
use Greenlight\Harness\IntegrationResources;

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

        Expect::that($resource->string('host'))->toBe('127.0.0.1')
            ->and($resource->int('port'))->toBe(5432)
            ->and($resource->bool('tls'))->toBeTrue()
            ->and($resource->float('ratio'))->toBe(1.5)
            ->and($resource->list('tags'))->toBe(['test', 'database'])
            ->and($resource->map('options'))->toBe(['timeout' => 3])
            ->and($resource->secret('password')->reveal())->toBe('do-not-print')
            ->and($resource->__debugInfo()['secrets'])->toBe(['password' => '[redacted]'])
            ->and(\var_export($resource, true))->not()->toContain('do-not-print')
            ->and(\var_export($resource->secret('password'), true))->not()->toContain('do-not-print');
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

        Expect::that($postgres->string('host'))->toBe('db')
            ->and($postgres->int('port'))->toBe(5432)
            ->and($postgres->secret('password')->reveal())->toBe('secret')
            ->and($restored->has('redis'))->toBeFalse();
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

        Expect::that($merged->string('host'))->toBe('db')
            ->and($merged->string('database'))->toBe('channel_2')
            ->and($merged->secret('password')->reveal())->toBe('channel-secret');
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

        Expect::that(($debug['fixtures']['database'] ?? null) === $resource)
            ->because('integration resource debug information MUST retain its fixture map')
            ->toBe(true);
        Expect::that(\is_string($dump) && \str_contains($dump, 'database'))
            ->because('integration resource dumps MUST identify their fixture IDs')
            ->toBe(true);
        Expect::that(\is_string($dump) && \str_contains($dump, $secret))
            ->because('integration resource dumps MUST NOT disclose nested secrets')
            ->toBe(false);
        Expect::that(\str_contains($export, $secret))
            ->because('integration resource exports MUST NOT disclose nested secrets')
            ->toBe(false);
    }

    #[Test]
    public function resourcesRejectInvalidFixtureMaps(): void
    {
        Expect::that(static fn(): IntegrationResources => new IntegrationResources([
            "\xB1\x31" => FixtureResource::empty(),
        ]))->toThrow(\InvalidArgumentException::class, matching: '/non-empty UTF-8 fixture IDs/');
    }

    #[Test]
    public function nonJsonValuesAreRejectedBeforeTransport(): void
    {
        Expect::that(static fn(): FixtureResource => FixtureResource::from(['stream' => \fopen('php://memory', 'rb')]))
            ->toThrow(\InvalidArgumentException::class, matching: '/JSON-safe/')
            ->and(static fn(): FixtureResource => FixtureResource::from(['number' => \INF]))
            ->toThrow(\InvalidArgumentException::class, matching: '/finite numbers/')
            ->and(static fn(): FixtureResource => FixtureResource::from(['text' => "\xB1\x31"]))
            ->toThrow(\InvalidArgumentException::class, matching: '/UTF-8/')
            ->and(static fn(): FixtureResource => FixtureResource::from(secrets: ['token' => "\xB1\x31"]))
            ->toThrow(\InvalidArgumentException::class, matching: '/UTF-8/');
    }

    /**
     * @param array<mixed> $secrets
     */
    #[Test]
    #[DataSet('invalidSecretTypes')]
    public function secretMapsRejectInvalidRuntimeTypes(array $secrets): void
    {
        Expect::that(static fn(): FixtureResource => FixtureResource::from(
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
}
