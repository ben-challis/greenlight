<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Coverage\Collection;

use Greenlight\Attribute\Test;
use Greenlight\Coverage\Collection\RawCoverage;
use Greenlight\Expect\Expect;

final readonly class RawCoverageTest
{
    #[Test]
    public function malformedDriverEntriesAreDiscarded(): void
    {
        $coverage = new RawCoverage([
            '/valid.php' => [
                10 => 1,
                11 => -1,
                'line' => 1,
                12 => 'covered',
            ],
            7 => [20 => 1],
            '/invalid.php' => 'not line coverage',
        ]);

        Expect::that($coverage->lines)
            ->because('raw coverage MUST keep only integer statuses keyed by integer lines')
            ->toBe([
                '/valid.php' => [
                    10 => 1,
                    11 => -1,
                ],
            ]);
    }

    #[Test]
    public function branchPayloadNormalizesOpcodePathsExitsAndHitState(): void
    {
        $coverage = new RawCoverage([
            '/src/Decision.php' => [
                'lines' => [10 => 1, 11 => -1],
                'functions' => [
                    'decide' => [
                        'branches' => [
                            [
                                'op_start' => 4,
                                'op_end' => 8,
                                'line_start' => 10,
                                'line_end' => 11,
                                'hit' => 1,
                                'out' => [9, 12],
                                'out_hit' => [1, 0],
                            ],
                            [
                                'op_start' => 9,
                                'op_end' => 11,
                                'line_start' => 11,
                                'line_end' => 11,
                                'hit' => 1,
                            ],
                            [
                                'op_start' => 12,
                                'op_end' => 14,
                                'line_start' => 11,
                                'line_end' => 11,
                                'hit' => 0,
                            ],
                        ],
                        'paths' => [
                            ['path' => [4, 9], 'hit' => 1],
                            ['path' => [4, 12], 'hit' => 0],
                        ],
                    ],
                ],
            ],
        ], true);

        Expect::that($coverage->toMap()->toWire())
            ->because('Xdebug branch data MUST remain distinct from line hit data')
            ->toBe([
                'branchCoverage' => true,
                'files' => [
                    '/src/Decision.php' => [
                        'covered' => [10],
                        'uncovered' => [11],
                        'functions' => [[
                            'name' => 'decide',
                            'branches' => [[
                                'id' => 0,
                                'startLine' => 10,
                                'endLine' => 11,
                                'covered' => true,
                                'exits' => [
                                    ['id' => 0, 'covered' => true],
                                    ['id' => 1, 'covered' => false],
                                ],
                            ],
                                [
                                    'id' => 1,
                                    'startLine' => 11,
                                    'endLine' => 11,
                                    'covered' => true,
                                    'exits' => [],
                                ],
                                [
                                    'id' => 2,
                                    'startLine' => 11,
                                    'endLine' => 11,
                                    'covered' => false,
                                    'exits' => [],
                                ],
                            ],
                            'paths' => [
                                ['branches' => [0, 1], 'covered' => true],
                                ['branches' => [0, 2], 'covered' => false],
                            ],
                        ]],
                    ],
                ],
            ]);
    }

    #[Test]
    public function processSpecificOpcodeOffsetsNormalizeBeforeMerge(): void
    {
        $map = (static fn(int $target, int $hit): array => [
            '/src/Decision.php' => [
                'lines' => [10 => $hit],
                'functions' => [
                    'decide' => [
                        'branches' => [
                            ['op_start' => 0, 'op_end' => 3, 'line_start' => 10, 'line_end' => 10, 'hit' => $hit, 'out' => [$target], 'out_hit' => [$hit]],
                            ['op_start' => $target, 'op_end' => $target + 2, 'line_start' => 11, 'line_end' => 11, 'hit' => $hit],
                        ],
                        'paths' => [['path' => [0, $target], 'hit' => $hit]],
                    ],
                ],
            ],
        ]);

        $merged = new RawCoverage($map(4, 0), true)->toMap()
            ->merge(new RawCoverage($map(6, 1), true)->toMap());

        Expect::that([
            $merged->coveredBranchTotal(),
            $merged->branchTotal(),
            $merged->coveredPathTotal(),
            $merged->pathTotal(),
        ])
            ->because('process-specific opcode offsets MUST NOT change branch or path identity')
            ->toBe([2, 2, 1, 1]);
    }
}
