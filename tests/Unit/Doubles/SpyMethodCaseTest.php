<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Doubles;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Doubles\Doubles;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Fixture\Doubles\Notifier;

final readonly class SpyMethodCaseTest
{
    public function __construct(private Doubles $doubles) {}

    #[Test]
    #[DataSet('methodNames')]
    public function recordedMethodNamesFollowPhpCaseInsensitivity(string $method): void
    {
        $notifier = $this->doubles->spy(Notifier::class);
        $notifier->notify('ops', 'deployed');

        Expect::that($this->doubles->callsTo($notifier, $method))
            ->because('recorded method names MUST follow PHP case-insensitive dispatch')
            ->toBe([['ops', 'deployed']]);
    }

    /**
     * @return iterable<string, array{non-empty-string}>
     */
    public static function methodNames(): iterable
    {
        yield 'upper case' => ['NOTIFY'];
        yield 'mixed case' => ['NoTiFy'];
    }
}
