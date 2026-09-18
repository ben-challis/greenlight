<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\IntegrationFixture;

use Greenlight\Attribute\Test;
use Greenlight\IntegrationFixture\FixtureResource;

use function Greenlight\expect;

final readonly class FixtureResourceSecretMergeTest
{
    #[Test]
    public function channelSecretsExtendAndOverrideSharedSecrets(): void
    {
        $shared = FixtureResource::from(secrets: [
            'token' => 'shared-token',
            'password' => 'shared-password',
        ]);
        $channel = FixtureResource::from(secrets: [
            'password' => 'channel-password',
            'certificate' => 'channel-certificate',
        ]);

        $merged = $shared->mergedWith($channel);

        expect($merged->secret('token')->reveal())
            ->because('channel resources MUST preserve unrelated shared secrets')
            ->toBe('shared-token');
        expect($merged->secret('password')->reveal())
            ->because('channel secrets MUST override shared secrets with the same key')
            ->toBe('channel-password');
        expect($merged->secret('certificate')->reveal())
            ->because('channel resources MUST add channel-only secrets')
            ->toBe('channel-certificate');
    }
}
