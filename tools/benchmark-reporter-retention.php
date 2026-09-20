<?php

declare(strict_types=1);

namespace Greenlight\Tools;

use Greenlight\Event\TestFinished;
use Greenlight\Reporting\Output;
use Greenlight\Reporting\PlainReporter;
use Greenlight\Reporting\TtyReporter;
use Greenlight\Result\CapturedOutput;
use Greenlight\Result\Outcome;
use Greenlight\Result\TestResult;
use Greenlight\Test\TestId;

require \dirname(__DIR__) . '/vendor/autoload.php';

$kind = $argv[1] ?? 'plain';
$status = $argv[2] ?? 'skip';

if (!\in_array($kind, ['plain', 'tty'], true) || !\in_array($status, ['skip', 'retry'], true)) {
    throw new \InvalidArgumentException('Use plain or tty, followed by skip or retry.');
}

$output = new class implements Output {
    public string $bytes = '';

    #[\Override]
    public function write(string $text): void
    {
        $this->bytes .= $text;
    }
};
$reporter = $kind === 'plain' ? new PlainReporter($output) : new TtyReporter($output, color: false, cursor: false);
$outcome = $status === 'skip' ? Outcome::Skipped : Outcome::Passed;

// Load the reporter dependencies before the memory measurement.
$reporter->onEvent(new TestFinished(new TestResult(new TestId('ExampleTest', 'warmup'), Outcome::Passed, 0.01, 0), 1.0));
$before = \memory_get_usage();
$reference = null;

for ($i = 0; $i < 200; ++$i) {
    $captured = new CapturedOutput(\str_repeat(\chr(65 + $i % 26), 65_536));
    $result = new TestResult(
        new TestId('ExampleTest', 'case' . $i),
        $outcome,
        0.01,
        0,
        attempts: 2,
        skipReason: 'Example reason.',
        output: $captured,
    );
    $reference = \WeakReference::create($captured);
    $reporter->onEvent(new TestFinished($result, 2.0 + $i));
    unset($result, $captured);
}

$retained = \memory_get_usage() - $before;
$reporter->finish();

echo \json_encode([
    'reporter' => $kind,
    'outcome' => $status,
    'retained_bytes' => $retained,
    'retains_capture' => $reference?->get() !== null,
    'output_sha256' => \hash('sha256', $output->bytes),
], \JSON_THROW_ON_ERROR), "\n";
