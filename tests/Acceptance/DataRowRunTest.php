<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Core\Event\TestFinished;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Support\GreenlightCli;
use Greenlight\Tests\Support\JsonlEvents;
use Greenlight\Tests\Support\ProcessResult;

final class DataRowRunTest
{
    #[Test]
    public function inlineRowsRunAndFilterByLabel(): void
    {
        $result = $this->run('--workers=2');
        Expect::that($result->exitCode)->because('all inline data rows MUST run')->toBe(0);
        Expect::that($this->finishedTestIds($result))->because('all inline data rows MUST run')->toBe([
            'Greenlight\Tests\Fixture\DataRows\InlineRowsTest::acceptsWord[from attribute]',
            'Greenlight\Tests\Fixture\DataRows\InlineRowsTest::acceptsWord[from provider]',
            'Greenlight\Tests\Fixture\DataRows\InlineRowsTest::addsUp[#1]',
            'Greenlight\Tests\Fixture\DataRows\InlineRowsTest::addsUp[small]',
        ]);

        $result = $this->run('--filter=*[from attribute]');
        Expect::that($result->exitCode)->because('the label filter MUST select only its row')->toBe(0);
        Expect::that($this->finishedTestIds($result))->because('the label filter MUST select only its row')->toBe([
            'Greenlight\Tests\Fixture\DataRows\InlineRowsTest::acceptsWord[from attribute]',
        ]);
    }

    /**
     * @return list<string>
     */
    private function finishedTestIds(ProcessResult $result): array
    {
        $testIds = [];

        foreach (JsonlEvents::from($result) as $event) {
            if ($event instanceof TestFinished) {
                $testIds[] = (string) $event->result->id;
            }
        }

        \sort($testIds);

        return $testIds;
    }

    private function run(string ...$flags): ProcessResult
    {
        return GreenlightCli::run(\dirname(__DIR__) . '/Fixture/DataRows', ['run', '--reporter=jsonl', ...\array_values($flags)]);
    }
}
