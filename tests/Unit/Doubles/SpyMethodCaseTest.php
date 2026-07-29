<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Doubles;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Doubles\Doubles;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Fixture\Doubles\Notifier;

final class SpyMethodCaseTest
{
    #[Test]
    #[DataSet('methodNames')]
    public function recordedMethodNamesFollowPhpCaseInsensitivity(string $method): void
    {
        $doubles = new Doubles();
        $notifier = $doubles->spy(Notifier::class);
        $notifier->notify('ops', 'deployed');

        Expect::that($doubles->callsTo($notifier, $method))
            ->because('recorded method names MUST follow PHP case-insensitive dispatch')
            ->toBe([['ops', 'deployed']]);

        $doubles->dispose();
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
