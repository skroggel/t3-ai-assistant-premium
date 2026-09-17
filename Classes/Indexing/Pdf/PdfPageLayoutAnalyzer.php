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

        if ($columns['count'] > 1) {
            $regional = $this->renderRegions($segments, $columns['split'], $pageWidth);
            $layoutType = match (true) {
                $regional['hasColumns'] && ($regional['hasPlain'] || $regional['hasTables']) => 'mixed',
                $regional['hasColumns'] => 'columns',
                $regional['hasTables'] => 'table',
                default => 'plain',
            };
            $confidence = $layoutType === 'mixed' && $table['isTable']
                ? min($table['confidence'], $columns['confidence'])
                : $columns['confidence'];

            return new PdfLayoutAnalysis(
                $regional['text'],
                $layoutType,
                $regional['hasColumns'] ? $columns['count'] : 1,
                $table['rowCount'],
                $confidence,
            );
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
     * Returns the horizontal regions used by the reading-order analysis.
     * Coordinates remain in PDF user space and are converted for the backend
     * preview by the diagnostic service.
     *
     * @param array<int, array<int, mixed>> $positionedText
     * @return array<int, array<string, int|float|string>>
     */
    public function diagnoseRegions(array $positionedText): array
    {
        $rows = $this->positionedTextReader->read($positionedText);
        if ($rows === []) {
            return [];
        }

        $pageWidth = max(1.0, $this->maximumX($rows) - $this->minimumX($rows));
        $columns = $this->detectColumns($rows, $pageWidth);
        $split = $columns['count'] > 1
            ? $columns['split']
            : $this->minimumX($rows) + $pageWidth / 2;
        $result = [];

        foreach ($this->splitIntoRegions($rows, $split, $pageWidth) as $region) {
            $classification = $this->classifyRegion(
                $region,
                $split,
                $pageWidth,
                $columns['count'] > 1,
            );
            $bounds = $this->regionBounds($region);
            $number = count($result) + 1;
            $result[] = [
                'number' => $number,
                'type' => $classification['type'],
                'label' => match ($classification['type']) {
                    'columns' => 'Two-column text',
                    'table' => 'Table',
                    'side-by-side-tables' => 'Side-by-side tables',
                    default => 'Full width',
                },
                'columnCount' => $classification['isColumnRegion'] ? 2 : 1,
                'tableRowCount' => $classification['tableRowCount'],
                ...$bounds,
            ];
        }

        return $result;
    }

    /**
     * Segments a page into horizontal bands before choosing the reading order
     * for each band. Full-width headings stay in visual order, prose columns
     * are read column by column, and genuine cross-page tables remain row-wise.
     *
     * @param array<int, array{y: float, parts: array<int, array{xMin: float, xMax: float, text: string}>}> $rows
     * @return array{text: string, hasColumns: bool, hasTables: bool, hasPlain: bool}
     */
    private function renderRegions(array $rows, float $split, float $pageWidth): array
    {
        $blocks = [];
        $hasColumns = false;
        $hasTables = false;
        $hasPlain = false;

        foreach ($this->splitIntoRegions($rows, $split, $pageWidth) as $region) {
            $classification = $this->classifyRegion($region, $split, $pageWidth, true);

            if ($classification['isColumnRegion']) {
                $blocks[] = $this->layoutRenderer->renderColumnRegion(
                    $region,
                    $split,
                    $classification['hasIndependentTables'],
                );
                $hasColumns = true;
                $hasTables = $hasTables || $classification['hasIndependentTables'];
                continue;
            }

            $blocks[] = $this->layoutRenderer->renderRows($region);
            if ($classification['type'] === 'table') {
                $hasTables = true;
            } else {
                $hasPlain = true;
            }
        }

        return [
            'text' => implode("\n\n", array_filter($blocks)),
            'hasColumns' => $hasColumns,
            'hasTables' => $hasTables,
            'hasPlain' => $hasPlain,
        ];
    }

    /**
     * @param array<int, array{y: float, parts: array<int, array<string, mixed>>}> $region
     * @return array{type: string, isColumnRegion: bool, hasIndependentTables: bool, tableRowCount: int}
     */
    private function classifyRegion(
        array $region,
        float $split,
        float $pageWidth,
        bool $allowColumns,
    ): array {
        $table = $this->detectTable($region, $pageWidth);
        $columnStats = $this->columnStats($region, $split);
        $hasIndependentTables = $allowColumns && $this->hasIndependentSideTables($region, $split);
        $isColumnRegion = $allowColumns
            && $columnStats['leftRows'] >= 2
            && $columnStats['rightRows'] >= 2
            && $columnStats['leftRows'] + $columnStats['rightRows'] >= self::MIN_COLUMN_LINES * 2;
        $isSingleWideTable = $table['isTable'] && $columnStats['overlapRatio'] >= 0.7;
        $isColumnRegion = $isColumnRegion && !$isSingleWideTable;

        return [
            'type' => match (true) {
                $isColumnRegion && $hasIndependentTables => 'side-by-side-tables',
                $isColumnRegion => 'columns',
                $table['isTable'] => 'table',
                default => 'full-width',
            },
            'isColumnRegion' => $isColumnRegion,
            'hasIndependentTables' => $hasIndependentTables,
            'tableRowCount' => $table['rowCount'],
        ];
    }

    /**
     * @param array<int, array{y: float, parts: array<int, array<string, mixed>>}> $region
     * @return array{xMin: float, xMax: float, yBottom: float, yTop: float}
     */
    private function regionBounds(array $region): array
    {
        $xMin = PHP_FLOAT_MAX;
        $xMax = 0.0;
        $yBottom = PHP_FLOAT_MAX;
        $yTop = 0.0;
        foreach ($region as $row) {
            foreach ($row['parts'] as $part) {
                $fontSize = max(array_map(
                    static fn (array $atom): float => (float)($atom['fontSize'] ?? 10.0),
                    ($part['atoms'] ?? []) ?: [['fontSize' => 10.0]],
                ));
                $xMin = min($xMin, (float)$part['xMin']);
                $xMax = max($xMax, (float)$part['xMax']);
                $yBottom = min($yBottom, (float)$row['y'] - $fontSize * 0.25);
                $yTop = max($yTop, (float)$row['y'] + $fontSize);
            }
        }

        return [
            'xMin' => $xMin === PHP_FLOAT_MAX ? 0.0 : $xMin,
            'xMax' => $xMax,
            'yBottom' => $yBottom === PHP_FLOAT_MAX ? 0.0 : $yBottom,
            'yTop' => $yTop,
        ];
    }

    /**
     * Detects two independent side-by-side tables by requiring repeated cell
     * starts on both sides of the gutter. A prose column next to a bullet list
     * therefore remains prose instead of being serialized as table cells.
     *
     * @param array<int, array{parts: array<int, array<string, mixed>>}> $rows
     */
    private function hasIndependentSideTables(array $rows, float $split): bool
    {
        $starts = ['left' => [], 'right' => []];
        foreach ($rows as $row) {
            foreach ($row['parts'] as $part) {
                $side = (float)$part['xMin'] < $split ? 'left' : 'right';
                $starts[$side][] = (float)$part['xMin'];
            }
        }

        foreach ($starts as $sideStarts) {
            $clusters = [];
            foreach ($sideStarts as $start) {
                foreach ($clusters as $index => $cluster) {
                    if (abs($start - $cluster['x']) <= 8.0) {
                        $clusters[$index]['x'] = (
                            $cluster['x'] * $cluster['count'] + $start
                        ) / ($cluster['count'] + 1);
                        $clusters[$index]['count']++;
                        continue 2;
                    }
                }
                $clusters[] = ['x' => $start, 'count' => 1];
            }
            $stable = array_filter($clusters, static fn (array $cluster): bool => $cluster['count'] >= 2);
            if (count($stable) < 2) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<int, array{y: float, parts: array<int, array{xMin: float, xMax: float, text: string}>}> $rows
     * @return array<int, array<int, array{y: float, parts: array<int, array{xMin: float, xMax: float, text: string}>}>>
     */
    private function splitIntoRegions(array $rows, float $split, float $pageWidth): array
    {
        $gaps = [];
        for ($index = 1, $count = count($rows); $index < $count; $index++) {
            $gap = (float)$rows[$index - 1]['y'] - (float)$rows[$index]['y'];
            if ($gap > 0.5) {
                $gaps[] = $gap;
            }
        }
        sort($gaps);
        $medianGap = $gaps === [] ? 12.0 : $gaps[intdiv(count($gaps), 2)];
        $regionGap = max(24.0, $medianGap * 2.25);

        $regions = [];
        $current = [];
        $previousY = null;
        foreach ($rows as $row) {
            $gap = $previousY === null ? 0.0 : $previousY - (float)$row['y'];
            $isSpanning = $this->isFullWidthRow($row, $split, $pageWidth);
            if ($current !== [] && ($gap > $regionGap || $isSpanning)) {
                $regions[] = $current;
                $current = [];
            }
            if ($isSpanning) {
                $regions[] = [$row];
            } else {
                $current[] = $row;
            }
            $previousY = (float)$row['y'];
        }
        if ($current !== []) {
            $regions[] = $current;
        }

        return $regions;
    }

    /** @param array{parts: array<int, array{xMin: float, xMax: float}>} $row */
    private function isFullWidthRow(array $row, float $split, float $pageWidth): bool
    {
        if (count($row['parts']) !== 1) {
            return false;
        }
        $part = $row['parts'][0];
        $atomStarts = array_map('floatval', array_column($part['atoms'] ?? [], 'x'));
        sort($atomStarts);
        for ($index = 1, $count = count($atomStarts); $index < $count; $index++) {
            if ($atomStarts[$index] - $atomStarts[$index - 1] > max(20.0, $pageWidth * 0.08)) {
                return false;
            }
        }
        return $part['xMin'] < $split
            && $part['xMax'] > $split
            && $part['xMax'] - $part['xMin'] >= $pageWidth * 0.58;
    }

    /**
     * @param array<int, array{parts: array<int, array{xMin: float, xMax: float}>}> $rows
     * @return array{leftRows: int, rightRows: int, overlapRatio: float}
     */
    private function columnStats(array $rows, float $split): array
    {
        $leftRows = [];
        $rightRows = [];
        foreach ($rows as $rowIndex => $row) {
            foreach ($row['parts'] as $part) {
                $positions = array_column($part['atoms'] ?? [], 'x');
                if ($positions === []) {
                    $positions = [$part['xMin']];
                }
                foreach ($positions as $position) {
                    if ((float)$position < $split) {
                        $leftRows[$rowIndex] = true;
                    } else {
                        $rightRows[$rowIndex] = true;
                    }
                }
            }
        }
        $overlap = count(array_intersect_key($leftRows, $rightRows));

        return [
            'leftRows' => count($leftRows),
            'rightRows' => count($rightRows),
            'overlapRatio' => $overlap / max(1, min(count($leftRows), count($rightRows))),
        ];
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
                $positions = array_column($part['atoms'] ?? [], 'x');
                if ($positions === []) {
                    $positions = [$part['xMin']];
                }
                foreach ($positions as $position) {
                    if ((float)$position < $split) {
                        $leftRows[$rowIndex] = true;
                    } else {
                        $rightRows[$rowIndex] = true;
                    }
                }
                if ($part['xMin'] < $split && $part['xMax'] > $split) {
                    $spanning++;
                }
            }
        }
        $overlap = count(array_intersect_key($leftRows, $rightRows));
        if (count($leftRows) < self::MIN_COLUMN_LINES || count($rightRows) < self::MIN_COLUMN_LINES) {
            return ['count' => 1, 'split' => 0.0, 'confidence' => 0.0];
        }

        $sideCoverage = min(count($leftRows), count($rightRows)) / max(1, max(count($leftRows), count($rightRows)));
        $confidence = min(
            0.98,
            0.5 + $sideCoverage * 0.2 + $overlap / max(1, count($rows)) * 0.25 - $spanning * 0.005,
        );
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
            $this->clusterStarts($rows, max(4.0, min(9.0, $pageWidth * 0.015)), true),
            static fn (array $anchor): bool => $anchor['count'] >= self::MIN_COLUMN_LINES,
        ));
        usort($anchors, static fn (array $left, array $right): int => $left['x'] <=> $right['x']);

        if ($anchors !== []) {
            $allStarts = [];
            foreach ($rows as $row) {
                foreach ($row['parts'] as $part) {
                    $starts = array_column($part['atoms'] ?? [], 'x');
                    array_push($allStarts, ...($starts === [] ? [$part['xMin']] : $starts));
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
    private function clusterStarts(array $rows, float $tolerance, bool $useAtoms = false): array
    {
        $clusters = [];
        foreach ($rows as $row) {
            foreach ($row['parts'] as $part) {
                $starts = $useAtoms ? array_column($part['atoms'] ?? [], 'x') : [];
                foreach ($starts === [] ? [$part['xMin']] : $starts as $start) {
                    foreach ($clusters as $index => $cluster) {
                        if (abs((float)$start - $cluster['x']) <= $tolerance) {
                            $clusters[$index]['x'] = (
                                $cluster['x'] * $cluster['count'] + (float)$start
                            ) / ($cluster['count'] + 1);
                            $clusters[$index]['count']++;
                            continue 2;
                        }
                    }
                    $clusters[] = ['x' => (float)$start, 'count' => 1];
                }
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
