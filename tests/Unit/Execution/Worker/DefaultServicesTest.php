<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Execution\Worker;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Execution\Worker\DefaultServices;
use Greenlight\Expect\Expect;
use Greenlight\Harness\HarnessScopes;
use Greenlight\Sandbox\EnvironmentVariables;
use Greenlight\Test\TestChannel;

final readonly class DefaultServicesTest
{
    public function __construct(private EnvironmentVariables $environment) {}

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

            Expect::that($channel->number)
                ->because('the default channel service MUST always use a positive number')
                ->toBe($expected);
        } finally {
            $scopes->closeWorker();
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
        yield 'numeric prefix' => ['3workers', 1];
        yield 'integer overflow' => [\str_repeat('9', 30), 1];
        yield 'positive' => ['3', 3];
    }
}
