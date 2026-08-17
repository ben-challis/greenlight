<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Harness;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Harness\FixtureResource;

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

        Expect::that($merged->secret('token')->reveal())
            ->because('channel resources MUST preserve unrelated shared secrets')
            ->toBe('shared-token');
        Expect::that($merged->secret('password')->reveal())
            ->because('channel secrets MUST override shared secrets with the same key')
            ->toBe('channel-password');
        Expect::that($merged->secret('certificate')->reveal())
            ->because('channel resources MUST add channel-only secrets')
            ->toBe('channel-certificate');
    }
}
