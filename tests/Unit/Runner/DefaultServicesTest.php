<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Core\Test\TestChannel;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Fixture\EnvironmentSandbox;
use Greenlight\Harness\HarnessScopes;
use Greenlight\Runner\DefaultServices;

final readonly class DefaultServicesTest
{
    public function __construct(private EnvironmentSandbox $environment) {}

    #[Test]
    #[DataSet('channelEnvironmentValues')]
    public function channelServiceUsesAPositiveEnvironmentValueOrOne(?string $raw, int $expected): void
    {
        if ($raw === null) {
            $this->environment->unset('GREENLIGHT_CHANNEL');
        } else {
            $this->environment->set('GREENLIGHT_CHANNEL', $raw);
        }

        $scopes = new HarnessScopes(DefaultServices::registry());

        try {
            $channel = $scopes->resolve(TestChannel::class, self::class);

            if (!$channel instanceof TestChannel) {
                Fail::because(\sprintf(
                    'Expected the default service to resolve TestChannel, got %s.',
                    \get_debug_type($channel),
                ));
            }

            Expect::that($channel->number)
                ->because('the default channel service MUST always use a positive number')
                ->toBe($expected);
        } finally {
            $scopes->closeRun();
        }
    }

    /**
     * @return iterable<string, array{?string, positive-int}>
     */
    public static function channelEnvironmentValues(): iterable
    {
        yield 'missing' => [null, 1];
        yield 'zero' => ['0', 1];
        yield 'negative' => ['-2', 1];
        yield 'not numeric' => ['worker', 1];
        yield 'positive' => ['3', 3];
    }
}
