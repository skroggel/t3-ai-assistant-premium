<?php
declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace Madj2k\AiAssistantPremium\Indexing\Pdf;

/**
 * Reconstructs reading order from PDF text coordinates.
 *
 * The analysis deliberately uses no language or domain-specific keywords.
 */
final class PdfPageLayoutAnalyzer
{
    private const MIN_COLUMN_LINES = 4;
    private const MIN_TABLE_ROWS = 3;

    public function __construct(
        private readonly PdfPositionedTextReader $positionedTextReader = new PdfPositionedTextReader(),
        private readonly PdfLayoutRenderer $layoutRenderer = new PdfLayoutRenderer(),
    ) {
    }

    /**
     * @param array<int, array<int, mixed>> $positionedText Smalot getDataTm() output.
     */
    public function analyze(array $positionedText): PdfLayoutAnalysis
    {
        $segments = $this->positionedTextReader->read($positionedText);
        if ($segments === []) {
            return new PdfLayoutAnalysis('', 'empty', 0, 0, 0.0);
        }
        $pageWidth = max(1.0, $this->maximumX($segments) - $this->minimumX($segments));
        $table = $this->detectTable($segments, $pageWidth);
        $columns = $this->detectColumns($segments, $pageWidth);

        if ($table['isTable'] && $columns['count'] > 1) {
            $rowText = $this->layoutRenderer->renderRows($segments);
            $mixedText = $this->layoutRenderer->renderMixedLayout($segments, $columns['split']);
            if ($mixedText !== $rowText) {
                return new PdfLayoutAnalysis(
                    $mixedText,
                    'mixed',
                    $columns['count'],
                    $table['rowCount'],
                    min($table['confidence'], $columns['confidence']),
                );
            }
        }

        if ($table['isTable']) {
            return new PdfLayoutAnalysis(
                $this->layoutRenderer->renderRows($segments),
                'table',
                max(1, $columns['count']),
                $table['rowCount'],
                $table['confidence'],
            );
        }

        if ($columns['count'] > 1) {
            return new PdfLayoutAnalysis(
                $this->layoutRenderer->renderColumns($segments, $columns['split']),
                'columns',
                $columns['count'],
                0,
                $columns['confidence'],
            );
        }

        return new PdfLayoutAnalysis(
            $this->layoutRenderer->renderRows($segments),
            'plain',
            1,
            0,
            0.9,
        );
    }

    /**
     * @param array<int, array{y: float, parts: array<int, array{xMin: float, xMax: float, text: string}>}> $rows
     * @return array{isTable: bool, rowCount: int, confidence: float}
     */
    private function detectTable(array $rows, float $pageWidth): array
    {
        $multiPartRows = array_values(array_filter(
            $rows,
            static fn (array $row): bool => count($row['parts']) >= 2 && count($row['parts']) <= 8,
        ));
        if (count($multiPartRows) < self::MIN_TABLE_ROWS) {
            return ['isTable' => false, 'rowCount' => 0, 'confidence' => 0.0];
        }

        $tolerance = max(3.0, min(8.0, $pageWidth * 0.012));
        $anchors = $this->clusterStarts($multiPartRows, $tolerance);
        $stableAnchors = array_values(array_filter(
            $anchors,
            static fn (array $anchor): bool => $anchor['count'] >= self::MIN_TABLE_ROWS,
        ));

        if (count($stableAnchors) < 2) {
            return ['isTable' => false, 'rowCount' => 0, 'confidence' => 0.0];
        }

        $tableRows = 0;
        $fillRatios = [];
        $cellLengths = [];
        foreach ($multiPartRows as $row) {
            $matches = 0;
            foreach ($row['parts'] as $part) {
                foreach ($stableAnchors as $anchor) {
                    if (abs($part['xMin'] - $anchor['x']) <= $tolerance) {
                        $matches++;
                        break;
                    }
                }
            }
            if ($matches >= 2) {
                $tableRows++;
                $span = max(1.0, end($row['parts'])['xMax'] - $row['parts'][0]['xMin']);
                $filled = array_sum(array_map(
                    static fn (array $part): float => max(0.0, $part['xMax'] - $part['xMin']),
                    $row['parts'],
                ));
                $fillRatios[] = min(1.0, $filled / $span);
                foreach ($row['parts'] as $part) {
                    $cellLengths[] = mb_strlen($part['text']);
                }
            }
        }

        sort($fillRatios);
        sort($cellLengths);
        $medianFill = $fillRatios === [] ? 1.0 : $fillRatios[intdiv(count($fillRatios), 2)];
        $medianCellLength = $cellLengths === [] ? 0 : $cellLengths[intdiv(count($cellLengths), 2)];
        $coverage = $tableRows / max(1, count($multiPartRows));
        $isTable = $tableRows >= self::MIN_TABLE_ROWS
            && $coverage >= 0.45
            && (
                count($stableAnchors) >= 4
                || ($medianFill < 0.52 && $medianCellLength <= 24)
            );
        $confidence = min(0.99, 0.45 + $coverage * 0.3 + min(0.2, count($stableAnchors) * 0.04));

        return [
            'isTable' => $isTable,
            'rowCount' => $isTable ? $tableRows : 0,
            'confidence' => $isTable ? $confidence : 0.0,
        ];
    }

    /**
     * @param array<int, array{y: float, parts: array<int, array{xMin: float, xMax: float, text: string}>}> $rows
     * @return array{count: int, split: float, confidence: float}
     */
    private function detectColumns(array $rows, float $pageWidth): array
    {
        $allParts = [];
        foreach ($rows as $row) {
            foreach ($row['parts'] as $part) {
                $allParts[] = $part;
            }
        }
        if (count($allParts) < self::MIN_COLUMN_LINES * 2) {
            return ['count' => 1, 'split' => 0.0, 'confidence' => 0.0];
        }

        $split = $this->findColumnSplitFromStarts($rows, $pageWidth);
        $gapCandidates = [];
        $minimumGap = max(12.0, $pageWidth * 0.02);
        foreach ($rows as $row) {
            for ($index = 1, $count = count($row['parts']); $index < $count; $index++) {
                $leftPart = $row['parts'][$index - 1];
                $rightPart = $row['parts'][$index];
                $gap = $rightPart['xMin'] - $leftPart['xMax'];
                if ($gap >= $minimumGap) {
                    $gapCandidates[] = [
                        'split' => ($leftPart['xMax'] + $rightPart['xMin']) / 2,
                        'gap' => $gap,
                    ];
                }
            }
        }

        $clusters = [];
        $tolerance = max(6.0, $pageWidth * 0.025);
        foreach ($gapCandidates as $candidate) {
            foreach ($clusters as $index => $cluster) {
                if (abs($candidate['split'] - $cluster['split']) <= $tolerance) {
                    $clusters[$index]['split'] = (
                        $cluster['split'] * $cluster['count'] + $candidate['split']
                    ) / ($cluster['count'] + 1);
                    $clusters[$index]['count']++;
                    $clusters[$index]['totalGap'] += $candidate['gap'];
                    continue 2;
                }
            }
            $clusters[] = [
                'split' => $candidate['split'],
                'count' => 1,
                'totalGap' => $candidate['gap'],
            ];
        }

        usort($clusters, static function (array $left, array $right): int {
            $byCount = $right['count'] <=> $left['count'];
            return $byCount !== 0 ? $byCount : $right['totalGap'] <=> $left['totalGap'];
        });
        if ($split === 0.0) {
            foreach ($clusters as $cluster) {
                if ($cluster['count'] < self::MIN_COLUMN_LINES) {
                    continue;
                }
                $candidate = $cluster['split'];
                $left = count(array_filter($allParts, static fn (array $part): bool => $part['xMax'] < $candidate));
                $right = count(array_filter($allParts, static fn (array $part): bool => $part['xMin'] > $candidate));
                if ($left >= self::MIN_COLUMN_LINES && $right >= self::MIN_COLUMN_LINES) {
                    $split = $candidate;
                    break;
                }
            }
        }

        if ($split === 0.0) {
            return ['count' => 1, 'split' => 0.0, 'confidence' => 0.0];
        }

        $leftRows = [];
        $rightRows = [];
        $spanning = 0;
        foreach ($rows as $rowIndex => $row) {
            foreach ($row['parts'] as $part) {
                if ($part['xMax'] < $split) {
                    $leftRows[$rowIndex] = true;
                } elseif ($part['xMin'] > $split) {
                    $rightRows[$rowIndex] = true;
                } else {
                    $spanning++;
                }
            }
        }
        $overlap = count(array_intersect_key($leftRows, $rightRows));
        if ($overlap < self::MIN_COLUMN_LINES) {
            return ['count' => 1, 'split' => 0.0, 'confidence' => 0.0];
        }

        $confidence = min(0.98, 0.55 + $overlap / max(1, count($rows)) * 0.35 - $spanning * 0.005);
        return ['count' => 2, 'split' => $split, 'confidence' => max(0.5, $confidence)];
    }

    /**
     * Finds persistent left edges of text columns. The cut is placed just to
     * the left of the right-hand edge, not halfway between both column starts.
     *
     * @param array<int, array{y: float, parts: array<int, array{xMin: float, xMax: float, text: string}>}> $rows
     */
    private function findColumnSplitFromStarts(array $rows, float $pageWidth): float
    {
        $anchors = array_values(array_filter(
            $this->clusterStarts($rows, max(4.0, min(9.0, $pageWidth * 0.015))),
            static fn (array $anchor): bool => $anchor['count'] >= self::MIN_COLUMN_LINES,
        ));
        usort($anchors, static fn (array $left, array $right): int => $left['x'] <=> $right['x']);

        if ($anchors !== []) {
            $allStarts = [];
            foreach ($rows as $row) {
                foreach ($row['parts'] as $part) {
                    $allStarts[] = $part['xMin'];
                }
            }
            $minimum = min($allStarts);
            $maximum = max($allStarts);
            $startWidth = max(1.0, $maximum - $minimum);
            $target = $minimum + $startWidth * 0.5;
            $centralAnchors = array_values(array_filter(
                $anchors,
                static fn (array $anchor): bool => $anchor['x'] >= $minimum + $startWidth * 0.4
                    && $anchor['x'] <= $minimum + $startWidth * 0.65,
            ));
            if ($centralAnchors !== []) {
                usort(
                    $centralAnchors,
                    static fn (array $left, array $right): int => abs($left['x'] - $target) <=> abs($right['x'] - $target),
                );
                return $centralAnchors[0]['x'] - max(6.0, $pageWidth * 0.015);
            }
        }

        $best = null;
        $bestScore = 0;
        foreach ($anchors as $leftIndex => $left) {
            foreach (array_slice($anchors, $leftIndex + 1) as $right) {
                if ($right['x'] - $left['x'] < $pageWidth * 0.3) {
                    continue;
                }
                $score = min($left['count'], $right['count']);
                if ($score > $bestScore) {
                    $best = $right['x'];
                    $bestScore = $score;
                }
            }
        }

        return $best === null ? 0.0 : $best - max(6.0, $pageWidth * 0.015);
    }

    /**
     * @param array<int, array{y: float, parts: array<int, array{xMin: float, xMax: float, text: string}>}> $rows
     * @return array<int, array{x: float, count: int}>
     */
    private function clusterStarts(array $rows, float $tolerance): array
    {
        $clusters = [];
        foreach ($rows as $row) {
            foreach ($row['parts'] as $part) {
                foreach ($clusters as $index => $cluster) {
                    if (abs($part['xMin'] - $cluster['x']) <= $tolerance) {
                        $clusters[$index]['x'] = ($cluster['x'] * $cluster['count'] + $part['xMin']) / ($cluster['count'] + 1);
                        $clusters[$index]['count']++;
                        continue 2;
                    }
                }
                $clusters[] = ['x' => $part['xMin'], 'count' => 1];
            }
        }

        return $clusters;
    }

    /** @param array<int, array{parts: array<int, array{xMin: float, xMax: float}>}> $rows */
    private function minimumX(array $rows): float
    {
        $minimum = PHP_FLOAT_MAX;
        foreach ($rows as $row) {
            foreach ($row['parts'] as $part) {
                $minimum = min($minimum, $part['xMin']);
            }
        }
        return $minimum === PHP_FLOAT_MAX ? 0.0 : $minimum;
    }

    /** @param array<int, array{parts: array<int, array{xMin: float, xMax: float}>}> $rows */
    private function maximumX(array $rows): float
    {
        $maximum = 0.0;
        foreach ($rows as $row) {
            foreach ($row['parts'] as $part) {
                $maximum = max($maximum, $part['xMax']);
            }
        }
        return $maximum;
    }
}

