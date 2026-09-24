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
        $requiresPositionedText = $this->requiresPositionedText($segments);
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
                $regional['maxColumnCount'],
                $regional['tableRowCount'],
                $confidence,
                $requiresPositionedText,
            );
        }

        if ($table['isTable']) {
            return new PdfLayoutAnalysis(
                $this->layoutRenderer->renderRows($segments),
                'table',
                max(1, $columns['count']),
                $table['rowCount'],
                $table['confidence'],
                $requiresPositionedText,
            );
        }

        if ($columns['count'] > 1) {
            return new PdfLayoutAnalysis(
                $this->layoutRenderer->renderColumns($segments, $columns['split']),
                'columns',
                $columns['count'],
                0,
                $columns['confidence'],
                $requiresPositionedText,
            );
        }

        return new PdfLayoutAnalysis(
            $this->layoutRenderer->renderRows($segments),
            'plain',
            1,
            0,
            0.9,
            $requiresPositionedText,
        );
    }

    /**
     * Native PDF stream order is unreliable when differently sized text
     * objects share an optical row but use visibly different baselines. Two
     * or more such rows provide conservative evidence for geometry-based
     * left-to-right reconstruction even on an otherwise plain page.
     *
     * @param array<int, array{parts: array<int, array<string, mixed>>}> $rows
     */
    private function requiresPositionedText(array $rows): bool
    {
        $adjustedRows = 0;
        foreach ($rows as $row) {
            $atoms = [];
            foreach ($row['parts'] as $part) {
                array_push($atoms, ...($part['atoms'] ?? []));
            }
            if (count($atoms) < 2) {
                continue;
            }
            $baselines = array_map(static fn (array $atom): float => (float)$atom['y'], $atoms);
            $fontSizes = array_map(
                static fn (array $atom): float => (float)($atom['verticalScale'] ?? $atom['fontSize'] ?? 10.0),
                $atoms,
            );
            $legacyTolerance = max(1.5, min($fontSizes) * 0.25);
            if (max($baselines) - min($baselines) <= $legacyTolerance) {
                continue;
            }
            $adjustedRows++;
            if ($adjustedRows >= 2) {
                return true;
            }
        }
        return false;
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

        foreach ($this->splitIntoRegions(
            $rows,
            $split,
            $pageWidth,
            $columns['count'] > 1,
        ) as $region) {
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
                    'column-blocks' => 'Two-column blocks',
                    'multi-column-blocks' => 'Multi-column blocks',
                    'nested-columns' => 'Nested two-column layout',
                    'table' => 'Table',
                    'side-by-side-tables' => 'Side-by-side tables',
                    default => 'Full width',
                },
                'columnCount' => $classification['columnCount'],
                'columnSplits' => $classification['columnCount'] > 2
                    ? $classification['columnSplits']
                    : ($classification['hasNestedColumns']
                        ? $this->nestedColumnSplits($region, $split)
                        : ($classification['isColumnRegion'] ? [$split] : [])),
                'tableRowCount' => $classification['tableRowCount'],
                ...$bounds,
            ];
        }

        return $result;
    }

    /**
     * Returns the outer gutter plus every validated inner gutter. Diagnostic
     * line boxes need all of them: otherwise atoms from neighbouring nested
     * cards are merged into a visual line that no longer exists in the
     * reconstructed card-by-card reading order.
     *
     * @param array<int, array{y: float, parts: array<int, array<string, mixed>>}> $rows
     * @return array<int, float>
     */
    private function nestedColumnSplits(array $rows, float $outerSplit): array
    {
        $splits = [$outerSplit];
        foreach ($this->partitionRegionAtSplit($rows, $outerSplit) as $sideRows) {
            if (count($sideRows) < self::MIN_COLUMN_LINES * 2) {
                continue;
            }
            $sideWidth = max(1.0, $this->maximumX($sideRows) - $this->minimumX($sideRows));
            $columns = $this->detectNestedColumns($sideRows, $sideWidth);
            if ($columns['count'] > 1
                && $this->hasRepeatedNestedBands($sideRows, $columns['split'], $sideWidth)
            ) {
                $splits[] = (float)$columns['split'];
            }
        }

        sort($splits, SORT_NUMERIC);
        return array_values(array_unique($splits));
    }

    /**
     * Segments a page into horizontal bands before choosing the reading order
     * for each band. Full-width headings stay in visual order, prose columns
     * are read column by column, and genuine cross-page tables remain row-wise.
     *
     * @param array<int, array{y: float, parts: array<int, array{xMin: float, xMax: float, text: string}>}> $rows
     * @return array{text: string, hasColumns: bool, hasTables: bool, hasPlain: bool, maxColumnCount: int, tableRowCount: int}
     */
    private function renderRegions(
        array $rows,
        float $split,
        float $pageWidth,
        int $depth = 0,
    ): array
    {
        $blocks = [];
        $hasColumns = false;
        $hasTables = false;
        $hasPlain = false;
        $maxColumnCount = 1;
        $tableRowCount = 0;

        foreach ($this->splitIntoRegions($rows, $split, $pageWidth, true) as $region) {
            $classification = $this->classifyRegion($region, $split, $pageWidth, true);
            $maxColumnCount = max($maxColumnCount, $classification['columnCount']);

            if ($classification['columnCount'] > 2) {
                $blocks[] = $this->layoutRenderer->renderMultiColumnRegion(
                    $region,
                    $classification['columnSplits'],
                );
                $hasColumns = true;
                continue;
            }

            if ($classification['isColumnRegion']) {
                $blocks[] = $classification['hasNestedColumns'] && $depth < 2
                    ? $this->renderNestedColumnRegion($region, $split, $depth)
                    : $this->layoutRenderer->renderColumnRegion(
                        $region,
                        $split,
                        $classification['hasIndependentTables'],
                );
                $hasColumns = true;
                $hasTables = $hasTables || $classification['hasIndependentTables'];
                $tableRowCount += $classification['hasIndependentTables']
                    ? $classification['tableRowCount']
                    : 0;
                continue;
            }

            if ($classification['type'] === 'table') {
                $blocks[] = $this->layoutRenderer->renderRows($region);
                $hasTables = true;
                $tableRowCount += $classification['tableRowCount'];
            } else {
                $blocks[] = $this->layoutRenderer->renderPlainRows($region);
                $hasPlain = true;
            }
        }

        return [
            'text' => implode("\n\n", array_filter($blocks)),
            'hasColumns' => $hasColumns,
            'hasTables' => $hasTables,
            'hasPlain' => $hasPlain,
            'maxColumnCount' => $hasColumns ? $maxColumnCount : 1,
            'tableRowCount' => $tableRowCount,
        ];
    }

    /**
     * @param array<int, array{y: float, parts: array<int, array<string, mixed>>}> $region
     * @return array{type: string, isColumnRegion: bool, hasIndependentTables: bool, hasNestedColumns: bool, tableRowCount: int, columnCount: int, columnSplits: array<int, float>}
     */
    private function classifyRegion(
        array $region,
        float $split,
        float $pageWidth,
        bool $allowColumns,
    ): array {
        $table = $this->detectTable($region, $pageWidth);
        $multiColumns = $allowColumns
            ? $this->detectIndependentMultiColumnBlocks($region, $pageWidth)
            : ['count' => 1, 'splits' => []];
        $columnStats = $this->columnStats($region, $split);
        $hasIndependentTables = $allowColumns && $this->hasIndependentSideTables($region, $split);
        $hasDenseColumnEvidence = $allowColumns
            && $columnStats['leftRows'] >= 2
            && $columnStats['rightRows'] >= 2
            && $columnStats['leftRows'] + $columnStats['rightRows'] >= self::MIN_COLUMN_LINES * 2
            && $this->hasStableColumnAnchors($region, $split, $pageWidth);
        $hasPairedSparseBlocks = $allowColumns
            && (
                $this->hasPairedSparseBlocks($region, $split, $pageWidth)
                || $this->hasOffsetParallelBlocks($region, $split, $pageWidth)
            );
        $isMultiColumnRegion = $multiColumns['count'] > 2;
        $isColumnRegion = $isMultiColumnRegion || $hasDenseColumnEvidence || $hasPairedSparseBlocks;
        $fullWidthRows = count(array_filter(
            $region,
            fn (array $row): bool => $this->isFullWidthRow($row, $split, $pageWidth),
        ));
        $isPredominantlyFullWidth = $fullWidthRows / max(1, count($region)) >= 0.65;
        $isColumnRegion = $isColumnRegion && ($isMultiColumnRegion || !$isPredominantlyFullWidth);
        $isSingleWideTable = $table['isTable'] && $columnStats['overlapRatio'] >= 0.7;
        $isColumnRegion = $isColumnRegion && (!$isSingleWideTable || $isMultiColumnRegion);
        $hasNestedColumns = $isColumnRegion
            && !$isMultiColumnRegion
            && !$hasIndependentTables
            && $this->hasNestedColumnLayout($region, $split);

        return [
            'type' => match (true) {
                $isMultiColumnRegion => 'multi-column-blocks',
                $isColumnRegion && $hasIndependentTables => 'side-by-side-tables',
                $isColumnRegion && $hasNestedColumns => 'nested-columns',
                $isColumnRegion && $hasPairedSparseBlocks && !$hasDenseColumnEvidence => 'column-blocks',
                $isColumnRegion => 'columns',
                $table['isTable'] => 'table',
                default => 'full-width',
            },
            'isColumnRegion' => $isColumnRegion,
            'hasIndependentTables' => $hasIndependentTables,
            'hasNestedColumns' => $hasNestedColumns,
            'tableRowCount' => $table['rowCount'],
            'columnCount' => $isMultiColumnRegion ? $multiColumns['count'] : ($isColumnRegion ? 2 : 1),
            'columnSplits' => $multiColumns['splits'],
        ];
    }

    /**
     * True columns repeatedly start at the same left edges. A full-width line
     * may contain several PDF text objects on both sides of the calculated
     * split, but their continuation positions vary from line to line.
     *
     * @param array<int, array{parts: array<int, array<string, mixed>>}> $rows
     */
    private function hasStableColumnAnchors(array $rows, float $split, float $pageWidth): bool
    {
        $starts = ['left' => [], 'right' => []];
        foreach ($rows as $rowIndex => $row) {
            $rowStarts = ['left' => [], 'right' => []];
            foreach ($row['parts'] as $part) {
                foreach (($part['atoms'] ?? []) ?: [['x' => $part['xMin']]] as $atom) {
                    $side = (float)$atom['x'] < $split ? 'left' : 'right';
                    $rowStarts[$side][] = (float)$atom['x'];
                }
            }
            foreach ($rowStarts as $side => $sideStarts) {
                if ($sideStarts !== []) {
                    $starts[$side][] = ['x' => min($sideStarts), 'row' => $rowIndex];
                }
            }
        }

        $tolerance = max(8.0, $pageWidth * 0.025);
        foreach ($starts as $sideStarts) {
            $clusters = [];
            foreach ($sideStarts as $start) {
                foreach ($clusters as $index => $cluster) {
                    if (abs($start['x'] - $cluster['x']) <= $tolerance) {
                        $clusters[$index]['x'] = ($cluster['x'] * $cluster['count'] + $start['x']) / ($cluster['count'] + 1);
                        $clusters[$index]['count']++;
                        continue 2;
                    }
                }
                $clusters[] = ['x' => $start['x'], 'count' => 1];
            }
            if (max(array_column($clusters, 'count') ?: [0]) < self::MIN_COLUMN_LINES) {
                return false;
            }
        }

        return true;
    }

    /**
     * Detects a compact row of independent brochure cards. It deliberately
     * requires three persistent columns with a shared start and either
     * staggered endings or a visibly detached heading row. A regular table,
     * whose columns normally participate in uniform logical rows, therefore
     * keeps its row-wise representation.
     *
     * @param array<int, array{y: float, parts: array<int, array<string, mixed>>}> $rows
     * @return array{count: int, splits: array<int, float>}
     */
    private function detectIndependentMultiColumnBlocks(array $rows, float $pageWidth): array
    {
        $partCount = array_sum(array_map(
            static fn (array $row): int => count($row['parts']),
            $rows,
        ));
        // Independent cards consist of a few coherent text objects per row.
        // A much denser row is characteristic of prose exported word by word;
        // its ordinary word spaces must not become artificial column gutters.
        if ($partCount / max(1, count($rows)) > 9.0) {
            return ['count' => 1, 'splits' => []];
        }

        $atomAnchorCandidate = $this->detectThreeProseColumnsFromAtomAnchors($rows, $pageWidth);
        if ($atomAnchorCandidate['count'] > 1) {
            return $atomAnchorCandidate;
        }

        $partAnchorCandidate = $this->detectThreeColumnsFromPartAnchors($rows, $pageWidth);

        $minimumGutter = max(18.0, $pageWidth * 0.055);
        $minimumColumnWidth = $pageWidth * 0.16;
        $contentLeft = $this->minimumX($rows);
        $contentRight = $this->maximumX($rows);
        $gapCandidates = [];
        foreach ($rows as $row) {
            $parts = $row['parts'];
            usort($parts, static fn (array $left, array $right): int => $left['xMin'] <=> $right['xMin']);
            for ($index = 1, $count = count($parts); $index < $count; $index++) {
                $gap = (float)$parts[$index]['xMin'] - (float)$parts[$index - 1]['xMax'];
                if ($gap >= $minimumGutter) {
                    $gapCandidates[] = ((float)$parts[$index]['xMin'] + (float)$parts[$index - 1]['xMax']) / 2;
                }
            }
        }
        if (count($gapCandidates) < 6) {
            return $partAnchorCandidate;
        }

        $clusters = [];
        $tolerance = max(8.0, $pageWidth * 0.035);
        foreach ($gapCandidates as $candidate) {
            foreach ($clusters as $index => $cluster) {
                if (abs($candidate - $cluster['x']) <= $tolerance) {
                    $clusters[$index]['x'] = ($cluster['x'] * $cluster['count'] + $candidate) / ($cluster['count'] + 1);
                    $clusters[$index]['count']++;
                    continue 2;
                }
            }
            $clusters[] = ['x' => $candidate, 'count' => 1];
        }
        $clusters = array_values(array_filter(
            $clusters,
            static fn (array $cluster): bool => $cluster['count'] >= 3,
        ));
        usort($clusters, static fn (array $left, array $right): int => $right['count'] <=> $left['count']);
        if (count($clusters) < 2) {
            return $partAnchorCandidate;
        }

        $best = null;
        foreach ($clusters as $leftIndex => $left) {
            foreach (array_slice($clusters, $leftIndex + 1) as $right) {
                $splits = [(float)$left['x'], (float)$right['x']];
                sort($splits);
                if ($splits[1] - $splits[0] < $pageWidth * 0.2) {
                    continue;
                }
                $columnWidths = [
                    $splits[0] - $contentLeft,
                    $splits[1] - $splits[0],
                    $contentRight - $splits[1],
                ];
                if (min($columnWidths) < $minimumColumnWidth) {
                    continue;
                }
                $participation = [[], [], []];
                foreach ($rows as $rowIndex => $row) {
                    foreach ($row['parts'] as $part) {
                        foreach (($part['atoms'] ?? []) ?: [['x' => $part['xMin']]] as $atom) {
                            $column = (float)$atom['x'] < $splits[0] ? 0 : ((float)$atom['x'] < $splits[1] ? 1 : 2);
                            $participation[$column][$rowIndex] = true;
                        }
                    }
                }
                if (min(array_map('count', $participation)) < 3) {
                    continue;
                }
                $firstRows = array_map(static fn (array $items): int => min(array_keys($items)), $participation);
                $lastRows = array_map(static fn (array $items): int => max(array_keys($items)), $participation);
                $hasStaggeredEndings = count(array_unique($lastRows)) >= 3;
                $hasDetachedHeading = $this->hasDetachedMultiColumnHeading($rows, $participation);
                $hasCompactHeading = $this->hasCompactMultiColumnHeading(
                    $rows,
                    $participation,
                    $splits,
                );
                if (max($firstRows) - min($firstRows) > 1
                    || (!$hasDetachedHeading && (!$hasStaggeredEndings || !$hasCompactHeading))
                ) {
                    continue;
                }
                $commonRows = count(array_intersect_key(...$participation));
                if ($commonRows < 3) {
                    continue;
                }
                $score = $commonRows * 10 + $left['count'] + $right['count'];
                if ($best === null || $score > $best['score']) {
                    $best = ['splits' => $splits, 'score' => $score];
                }
            }
        }

        return $best === null
            ? $partAnchorCandidate
            : ['count' => 3, 'splits' => $best['splits']];
    }

    /**
     * Detects three prose columns when a PDF exporter combines the complete
     * visual row into one part but retains one text atom per column. Stable
     * anchors and substantial copy in every column distinguish this pattern
     * from ordinary word fragments and compact table cells.
     *
     * @param array<int, array{y: float, parts: array<int, array<string, mixed>>}> $rows
     * @return array{count: int, splits: array<int, float>}
     */
    private function detectThreeProseColumnsFromAtomAnchors(array $rows, float $pageWidth): array
    {
        $candidateRows = [];
        foreach ($rows as $rowIndex => $row) {
            $atoms = [];
            foreach ($row['parts'] as $part) {
                foreach ($part['atoms'] ?? [] as $atom) {
                    $text = trim((string)($atom['text'] ?? ''));
                    if ($text === '' || preg_match('/[\p{L}\p{N}]/u', $text) !== 1) {
                        continue;
                    }
                    $atoms[] = [
                        'x' => (float)$atom['x'],
                        'length' => mb_strlen($text),
                    ];
                }
            }
            usort($atoms, static fn (array $left, array $right): int => $left['x'] <=> $right['x']);
            if (count($atoms) === 3) {
                $candidateRows[$rowIndex] = $atoms;
            }
        }
        if (count($candidateRows) < self::MIN_COLUMN_LINES) {
            return ['count' => 1, 'splits' => []];
        }

        $anchors = [];
        $averageLengths = [];
        for ($column = 0; $column < 3; $column++) {
            $starts = array_column(array_column($candidateRows, $column), 'x');
            sort($starts);
            $anchors[$column] = $starts[intdiv(count($starts), 2)];
            $averageLengths[$column] = array_sum(array_column(
                array_column($candidateRows, $column),
                'length',
            )) / count($candidateRows);
        }

        $minimumColumnDistance = $pageWidth * 0.2;
        if ($anchors[1] - $anchors[0] < $minimumColumnDistance
            || $anchors[2] - $anchors[1] < $minimumColumnDistance
            || min($averageLengths) < 18.0
        ) {
            return ['count' => 1, 'splits' => []];
        }

        $tolerance = max(8.0, $pageWidth * 0.025);
        foreach ($candidateRows as $atoms) {
            for ($column = 0; $column < 3; $column++) {
                if (abs($atoms[$column]['x'] - $anchors[$column]) > $tolerance) {
                    return ['count' => 1, 'splits' => []];
                }
            }
        }

        return [
            'count' => 3,
            'splits' => [
                $anchors[1] - $this->columnSplitInset($pageWidth),
                $anchors[2] - $this->columnSplitInset($pageWidth),
            ],
        ];
    }

    /**
     * Detects three aligned cards from their persistent left edges. Card copy
     * can almost fill its visual column, leaving no sufficiently wide gutter
     * for the whitespace-based detector. Requiring three aligned rows plus a
     * compact or detached heading keeps fragmented prose out of this fallback.
     *
     * @param array<int, array{y: float, parts: array<int, array<string, mixed>>}> $rows
     * @return array{count: int, splits: array<int, float>}
     */
    private function detectThreeColumnsFromPartAnchors(array $rows, float $pageWidth): array
    {
        $candidateRows = [];
        foreach ($rows as $rowIndex => $row) {
            if (count($row['parts']) !== 3) {
                continue;
            }
            $parts = $row['parts'];
            usort($parts, static fn (array $left, array $right): int => $left['xMin'] <=> $right['xMin']);
            $candidateRows[$rowIndex] = array_map(
                static fn (array $part): float => (float)$part['xMin'],
                $parts,
            );
        }
        if (count($candidateRows) < 3) {
            return ['count' => 1, 'splits' => []];
        }

        $anchors = [];
        for ($column = 0; $column < 3; $column++) {
            $starts = array_column($candidateRows, $column);
            sort($starts);
            $anchors[$column] = $starts[intdiv(count($starts), 2)];
        }
        $minimumColumnDistance = $pageWidth * 0.2;
        if ($anchors[1] - $anchors[0] < $minimumColumnDistance
            || $anchors[2] - $anchors[1] < $minimumColumnDistance
        ) {
            return ['count' => 1, 'splits' => []];
        }

        $tolerance = max(8.0, $pageWidth * 0.025);
        foreach ($candidateRows as $starts) {
            for ($column = 0; $column < 3; $column++) {
                if (abs($starts[$column] - $anchors[$column]) > $tolerance) {
                    return ['count' => 1, 'splits' => []];
                }
            }
        }

        $splits = [
            ($anchors[0] + $anchors[1]) / 2,
            ($anchors[1] + $anchors[2]) / 2,
        ];
        $participation = [[], [], []];
        foreach (array_keys($candidateRows) as $rowIndex) {
            $participation[0][$rowIndex] = true;
            $participation[1][$rowIndex] = true;
            $participation[2][$rowIndex] = true;
        }
        if (!$this->hasDetachedMultiColumnHeading($rows, $participation)
            && !$this->hasCompactMultiColumnHeading($rows, $participation, $splits)
        ) {
            return ['count' => 1, 'splits' => []];
        }

        return ['count' => 3, 'splits' => $splits];
    }

    /**
     * Equal-height cards are distinguished from ordinary table rows by a
     * short shared heading row that is separated from the wrapped body text
     * by a clearly larger vertical gap.
     *
     * @param array<int, array{y: float, parts: array<int, array<string, mixed>>}> $rows
     * @param array<int, array<int, bool>> $participation
     */
    private function hasDetachedMultiColumnHeading(array $rows, array $participation): bool
    {
        $sharedRows = array_keys(array_intersect_key(...$participation));
        sort($sharedRows);
        if (count($sharedRows) < 4) {
            return false;
        }

        $headingRow = $sharedRows[0];
        $firstBodyRow = $sharedRows[1];
        $headingGap = (float)$rows[$headingRow]['y'] - (float)$rows[$firstBodyRow]['y'];
        $bodyGaps = [];
        for ($index = 2, $count = count($sharedRows); $index < $count; $index++) {
            $bodyGaps[] = (float)$rows[$sharedRows[$index - 1]]['y'] - (float)$rows[$sharedRows[$index]]['y'];
        }
        sort($bodyGaps);
        $medianBodyGap = $bodyGaps[intdiv(count($bodyGaps), 2)] ?? 0.0;
        if ($medianBodyGap <= 0.0
            || $headingGap < max(18.0, $medianBodyGap * 1.55)
        ) {
            return false;
        }

        $rowLength = static fn (array $row): int => array_sum(array_map(
            static fn (array $part): int => mb_strlen(trim((string)($part['text'] ?? ''))),
            $row['parts'],
        ));

        return $rowLength($rows[$headingRow]) < $rowLength($rows[$firstBodyRow]) * 0.75;
    }

    /**
     * Staggered prose fragments can accidentally form three geometric bands
     * when a PDF stores each visual line as many separate text objects. Real
     * brochure cards instead start with a compact heading row before their
     * longer body copy. Requiring that semantic shape keeps two-column prose
     * from being split into artificial third-columns.
     *
     * @param array<int, array{y: float, parts: array<int, array<string, mixed>>}> $rows
     * @param array<int, array<int, bool>> $participation
     * @param array<int, float> $splits
     */
    private function hasCompactMultiColumnHeading(
        array $rows,
        array $participation,
        array $splits,
    ): bool {
        $sharedRows = array_keys(array_intersect_key(...$participation));
        sort($sharedRows);
        if (count($sharedRows) < 3) {
            return false;
        }

        $lengthsByColumn = static function (array $row) use ($splits): array {
            $lengths = array_fill(0, count($splits) + 1, 0);
            foreach ($row['parts'] as $part) {
                foreach (($part['atoms'] ?? []) ?: [[
                    'x' => $part['xMin'],
                    'text' => $part['text'] ?? '',
                ]] as $atom) {
                    $column = 0;
                    while (isset($splits[$column]) && (float)$atom['x'] >= $splits[$column]) {
                        $column++;
                    }
                    $lengths[$column] += mb_strlen(trim((string)($atom['text'] ?? '')));
                }
            }
            return $lengths;
        };

        $headingLengths = $lengthsByColumn($rows[$sharedRows[0]]);
        $bodyLengths = array_fill(0, count($splits) + 1, 0);
        foreach (array_slice($sharedRows, 1, 3) as $rowIndex) {
            foreach ($lengthsByColumn($rows[$rowIndex]) as $column => $length) {
                $bodyLengths[$column] += $length;
            }
        }

        foreach ($headingLengths as $column => $headingLength) {
            $averageBodyLength = $bodyLengths[$column] / min(3, count($sharedRows) - 1);
            if ($headingLength === 0
                || $averageBodyLength <= 0.0
                || $headingLength >= $averageBodyLength * 0.72
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Renders an outer column and then analyses each side again. This handles
     * layouts such as a large callout beside a two-column card grid without
     * introducing image- or document-specific rules.
     *
     * @param array<int, array{y: float, parts: array<int, array<string, mixed>>}> $rows
     */
    private function renderNestedColumnRegion(array $rows, float $split, int $depth): string
    {
        $sides = $this->partitionRegionAtSplit($rows, $split);
        $blocks = [];
        foreach ([$sides['left'], $sides['right']] as $sideRows) {
            if ($sideRows === []) {
                continue;
            }
            $sideWidth = max(1.0, $this->maximumX($sideRows) - $this->minimumX($sideRows));
            $sideColumns = $this->detectNestedColumns($sideRows, $sideWidth);
            if ($sideColumns['count'] > 1) {
                $blocks[] = $this->renderRegions(
                    $sideRows,
                    $sideColumns['split'],
                    $sideWidth,
                    $depth + 1,
                )['text'];
                continue;
            }
            $blocks[] = $this->layoutRenderer->renderRows($sideRows);
        }

        return implode("\n\n", array_filter($blocks));
    }

    /**
     * @param array<int, array{y: float, parts: array<int, array<string, mixed>>}> $rows
     */
    private function hasNestedColumnLayout(array $rows, float $split): bool
    {
        foreach ($this->partitionRegionAtSplit($rows, $split) as $sideRows) {
            if (count($sideRows) < self::MIN_COLUMN_LINES * 2) {
                continue;
            }
            $sideWidth = max(1.0, $this->maximumX($sideRows) - $this->minimumX($sideRows));
            $columns = $this->detectNestedColumns($sideRows, $sideWidth);
            if ($columns['count'] > 1
                && $this->hasRepeatedNestedBands($sideRows, $columns['split'], $sideWidth)
            ) {
                return true;
            }
        }
        return false;
    }

    /**
     * A nested card grid has at least two vertically separated bands and both
     * inner columns participate in each band. This prevents ordinary prose
     * columns or a single left/right content pair from being reclassified.
     *
     * @param array<int, array{y: float, parts: array<int, array<string, mixed>>}> $rows
     */
    private function hasRepeatedNestedBands(array $rows, float $split, float $pageWidth): bool
    {
        $pairedBands = 0;
        foreach ($this->splitIntoRegions($rows, $split, $pageWidth, false) as $band) {
            $stats = $this->columnStats($band, $split);
            $rowsWithGenuineGutter = count(array_filter(
                $band,
                fn (array $row): bool => $this->hasGenuineColumnGutter($row, $split, $pageWidth),
            ));
            // A long prose line naturally has words on both sides of almost
            // every possible split. Nested cards, unlike prose followed by a
            // small table, expose a visible inner gutter in every repeated
            // band. Requiring that geometry prevents ordinary copy from being
            // serialized as a second-level column layout.
            if ($stats['leftRows'] >= 2
                && $stats['rightRows'] >= 2
                && $rowsWithGenuineGutter >= 1
            ) {
                $pairedBands++;
            }
        }
        return $pairedBands >= 2;
    }

    /**
     * Centred card captions do not share a stable left edge. For nested
     * layouts only, fall back to a strong bimodal cluster of text starts when
     * the regular prose-column detector cannot determine a split.
     *
     * @param array<int, array{y: float, parts: array<int, array<string, mixed>>}> $rows
     * @return array{count: int, split: float, confidence: float}
     */
    private function detectNestedColumns(array $rows, float $pageWidth): array
    {
        $columns = $this->detectColumns($rows, $pageWidth);
        if ($columns['count'] > 1) {
            return $columns;
        }

        $starts = [];
        foreach ($rows as $rowIndex => $row) {
            foreach ($row['parts'] as $part) {
                foreach (($part['atoms'] ?? []) ?: [['x' => $part['xMin']]] as $atom) {
                    $starts[] = ['x' => (float)$atom['x'], 'row' => $rowIndex];
                }
            }
        }
        usort($starts, static fn (array $left, array $right): int => $left['x'] <=> $right['x']);
        if (count($starts) < self::MIN_COLUMN_LINES * 2) {
            return $columns;
        }

        $minimumGap = max(30.0, $pageWidth * 0.18);
        $best = null;
        for ($index = 1, $count = count($starts); $index < $count; $index++) {
            $gap = $starts[$index]['x'] - $starts[$index - 1]['x'];
            if ($gap < $minimumGap) {
                continue;
            }
            $split = ($starts[$index]['x'] + $starts[$index - 1]['x']) / 2;
            $leftRows = [];
            $rightRows = [];
            foreach ($starts as $start) {
                if ($start['x'] < $split) {
                    $leftRows[$start['row']] = true;
                } else {
                    $rightRows[$start['row']] = true;
                }
            }
            if (count($leftRows) < self::MIN_COLUMN_LINES
                || count($rightRows) < self::MIN_COLUMN_LINES
            ) {
                continue;
            }
            $score = $gap * min(count($leftRows), count($rightRows));
            if ($best === null || $score > $best['score']) {
                $best = ['split' => $split, 'score' => $score, 'gap' => $gap];
            }
        }

        if ($best === null) {
            return $columns;
        }

        return [
            'count' => 2,
            'split' => $best['split'],
            'confidence' => min(0.9, 0.55 + $best['gap'] / max(1.0, $pageWidth) * 0.5),
        ];
    }

    /**
     * @param array<int, array{y: float, parts: array<int, array<string, mixed>>}> $rows
     * @return array{left: array<int, array<string, mixed>>, right: array<int, array<string, mixed>>}
     */
    private function partitionRegionAtSplit(array $rows, float $split): array
    {
        $left = [];
        $right = [];
        foreach ($rows as $row) {
            $partition = $this->layoutRenderer->partitionRowAtSplit($row, $split);
            if ($partition['left'] !== []) {
                $left[] = ['y' => $row['y'], 'parts' => $partition['left']];
            }
            if ($partition['right'] !== []) {
                $right[] = ['y' => $row['y'], 'parts' => $partition['right']];
            }
        }
        return ['left' => $left, 'right' => $right];
    }

    /**
     * Detects short left/right content pairs such as captions below a grid of
     * images. The page-wide column detector must already have supplied a split;
     * this local check additionally requires two genuinely separate objects
     * with a visible gutter on at least one common row.
     *
     * @param array<int, array{parts: array<int, array<string, mixed>>}> $rows
     */
    private function hasPairedSparseBlocks(array $rows, float $split, float $pageWidth): bool
    {
        $minimumGutter = max(12.0, $pageWidth * 0.03);
        foreach ($rows as $row) {
            if (count($row['parts']) !== 2) {
                continue;
            }
            $left = array_values(array_filter(
                $row['parts'],
                static fn (array $part): bool => (float)$part['xMax'] < $split,
            ));
            $right = array_values(array_filter(
                $row['parts'],
                static fn (array $part): bool => (float)$part['xMin'] > $split,
            ));
            // More than one object on either side indicates a table or a
            // three-plus-column grid, not the paired two-block pattern handled
            // here.
            if (count($left) !== 1 || count($right) !== 1) {
                continue;
            }

            $leftEdge = max(array_column($left, 'xMax'));
            $rightEdge = min(array_column($right, 'xMin'));
            if ($rightEdge - $leftEdge >= $minimumGutter) {
                return true;
            }
        }

        return false;
    }

    /**
     * Detects short parallel blocks whose baselines do not line up. This is a
     * common layout for a multi-line callout next to a prose paragraph: a
     * row-based detector would alternate both sides by their Y coordinates.
     * Requiring a real gutter and substantially overlapping vertical ranges
     * keeps consecutive single-column blocks out of this classification.
     *
     * @param array<int, array{y: float, parts: array<int, array<string, mixed>>}> $rows
     */
    private function hasOffsetParallelBlocks(array $rows, float $split, float $pageWidth): bool
    {
        $leftRows = [];
        $rightRows = [];
        $leftEdge = -PHP_FLOAT_MAX;
        $rightEdge = PHP_FLOAT_MAX;

        foreach ($rows as $rowIndex => $row) {
            $leftAtoms = [];
            $rightAtoms = [];
            foreach ($row['parts'] as $part) {
                foreach ($part['atoms'] ?? [] as $atom) {
                    if ((float)$atom['x'] < $split) {
                        $leftAtoms[] = $atom;
                    } else {
                        $rightAtoms[] = $atom;
                    }
                }
            }

            if ($leftAtoms !== []) {
                usort($leftAtoms, static fn (array $left, array $right): int => $left['x'] <=> $right['x']);
                $leftRows[$rowIndex] = (float)$row['y'];
                $leftEdge = max(
                    $leftEdge,
                    (float)$this->positionedTextReader->createSegment($leftAtoms)['xMax'],
                );
            }
            if ($rightAtoms !== []) {
                usort($rightAtoms, static fn (array $left, array $right): int => $left['x'] <=> $right['x']);
                $rightRows[$rowIndex] = (float)$row['y'];
                $rightEdge = min(
                    $rightEdge,
                    (float)$this->positionedTextReader->createSegment($rightAtoms)['xMin'],
                );
            }
        }

        if (count($leftRows) < 2
            || count($rightRows) < 2
            || count($leftRows) + count($rightRows) < 6
        ) {
            return false;
        }

        $minimumGutter = max(12.0, $pageWidth * 0.03);
        if ($rightEdge - $leftEdge < $minimumGutter) {
            return false;
        }

        $leftTop = max($leftRows);
        $leftBottom = min($leftRows);
        $rightTop = max($rightRows);
        $rightBottom = min($rightRows);
        $overlap = max(0.0, min($leftTop, $rightTop) - max($leftBottom, $rightBottom));
        $shorterSpan = max(1.0, min($leftTop - $leftBottom, $rightTop - $rightBottom));

        return $overlap / $shorterSpan >= 0.5;
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

        foreach ($this->partitionRegionAtSplit($rows, $split) as $sideRows) {
            if ($sideRows === []) {
                return false;
            }
            $sideWidth = max(1.0, $this->maximumX($sideRows) - $this->minimumX($sideRows));
            if (!$this->detectTable($sideRows, $sideWidth)['isTable']) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<int, array{y: float, parts: array<int, array{xMin: float, xMax: float, text: string}>}> $rows
     * @return array<int, array<int, array{y: float, parts: array<int, array{xMin: float, xMax: float, text: string}>}>>
     */
    private function splitIntoRegions(
        array $rows,
        float $split,
        float $pageWidth,
        bool $separateSpanningBands,
    ): array
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
        $asymmetricColumnRange = $separateSpanningBands
            ? $this->findAsymmetricColumnRange($rows, $split, $pageWidth, $regionGap)
            : null;

        $regions = [];
        $current = [];
        $currentIsSpanning = null;
        $previousY = null;
        $previousIndex = null;
        foreach ($rows as $rowIndex => $row) {
            $gap = $previousY === null ? 0.0 : $previousY - (float)$row['y'];
            $insideAsymmetricColumns = $asymmetricColumnRange !== null
                && $rowIndex >= $asymmetricColumnRange['start']
                && $rowIndex <= $asymmetricColumnRange['end'];
            $previousInsideAsymmetricColumns = $asymmetricColumnRange !== null
                && $previousIndex !== null
                && $previousIndex >= $asymmetricColumnRange['start']
                && $previousIndex <= $asymmetricColumnRange['end'];
            $isSpanning = $separateSpanningBands && !$insideAsymmetricColumns
                && $this->isFullWidthRow($row, $split, $pageWidth);
            $layoutModeChanged = $currentIsSpanning !== null
                && $currentIsSpanning !== $isSpanning;
            $continuesColumnTail = $layoutModeChanged
                && $currentIsSpanning === false
                && $isSpanning
                && $gap <= $regionGap
                && $this->continuesEstablishedColumn($current, $row, $split, $pageWidth);
            $continuesFullWidthTail = $layoutModeChanged
                && $currentIsSpanning === true
                && !$isSpanning
                && $gap <= $regionGap
                && $this->continuesEstablishedFullWidthBand($current, $row, $pageWidth);
            $startsFullWidthBand = $layoutModeChanged
                && $currentIsSpanning === false
                && $isSpanning
                && $gap <= $regionGap
                && $this->startsFullWidthBand($current, $row, $pageWidth);
            $keepsAsymmetricColumnTogether = $insideAsymmetricColumns
                && $previousInsideAsymmetricColumns;
            $crossesAsymmetricBoundary = $asymmetricColumnRange !== null
                && $previousIndex !== null
                && $insideAsymmetricColumns !== $previousInsideAsymmetricColumns;
            if ($current !== [] && (
                $crossesAsymmetricBoundary
                || ($gap > $regionGap && !$keepsAsymmetricColumnTogether)
                || ($layoutModeChanged
                    && !$continuesColumnTail
                    && !$continuesFullWidthTail
                    && !$startsFullWidthBand)
            )) {
                $regions[] = $current;
                $current = [];
            }
            $current[] = $row;
            // A long line in the remaining side of an unequal-height column
            // can cross the calculated split merely because its width is
            // estimated. Keep the established column mode for that tail.
            $currentIsSpanning = match (true) {
                $crossesAsymmetricBoundary => $isSpanning,
                $continuesColumnTail => false,
                $continuesFullWidthTail => true,
                $startsFullWidthBand => true,
                default => $isSpanning,
            };
            $previousY = (float)$row['y'];
            $previousIndex = $rowIndex;
        }
        if ($current !== []) {
            $regions[] = $current;
        }

        return $regions;
    }

    /**
     * Keeps a short final line with an established page-wide callout. The
     * final line may no longer cross the column split simply because it
     * contains fewer words than the lines above it.
     *
     * @param array<int, array{y: float, parts: array<int, array<string, mixed>>}> $current
     * @param array{y: float, parts: array<int, array<string, mixed>>} $row
     */
    private function continuesEstablishedFullWidthBand(
        array $current,
        array $row,
        float $pageWidth,
    ): bool {
        $rowStarts = [];
        foreach ($row['parts'] as $part) {
            $starts = array_column($part['atoms'] ?? [], 'x');
            array_push($rowStarts, ...($starts === [] ? [$part['xMin']] : $starts));
        }
        if ($rowStarts === []) {
            return false;
        }
        $rowStart = min(array_map('floatval', $rowStarts));
        $anchorTolerance = max(12.0, $pageWidth * 0.04);

        foreach (array_reverse($current) as $currentRow) {
            foreach ($currentRow['parts'] as $part) {
                if ((float)$part['xMax'] - (float)$part['xMin'] < $pageWidth * 0.58) {
                    continue;
                }
                if (abs((float)$part['xMin'] - $rowStart) <= $anchorTolerance) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Joins a short section heading to immediately following page-wide text.
     * This is the inverse of continuesEstablishedFullWidthBand(): the heading
     * itself is too short to cross the column split, while the first content
     * line provides the full-width evidence.
     *
     * @param array<int, array{y: float, parts: array<int, array<string, mixed>>}> $current
     * @param array{y: float, parts: array<int, array<string, mixed>>} $row
     */
    private function startsFullWidthBand(array $current, array $row, float $pageWidth): bool
    {
        if (count($current) > 2) {
            return false;
        }
        $headingStarts = [];
        foreach ($current as $currentRow) {
            foreach ($currentRow['parts'] as $part) {
                $headingStarts[] = (float)$part['xMin'];
            }
        }
        if ($headingStarts === []) {
            return false;
        }

        $anchorTolerance = max(12.0, $pageWidth * 0.04);
        foreach ($row['parts'] as $part) {
            if ((float)$part['xMax'] - (float)$part['xMin'] < $pageWidth * 0.58) {
                continue;
            }
            foreach ($headingStarts as $headingStart) {
                if (abs($headingStart - (float)$part['xMin']) <= $anchorTolerance) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Finds one continuous but vertically asymmetric two-column block. PDF
     * brochures commonly start a short callout column above the main column
     * and end it before the main prose is finished. The shared middle rows
     * provide the reliable column evidence; adjacent rows on only one side
     * are then retained in that same block instead of being emitted row-wise.
     *
     * @param array<int, array{y: float, parts: array<int, array<string, mixed>>}> $rows
     * @return array{start: int, end: int}|null
     */
    private function findAsymmetricColumnRange(
        array $rows,
        float $split,
        float $pageWidth,
        float $regionGap,
    ): ?array {
        $sides = [];

        foreach ($rows as $rowIndex => $row) {
            $leftStarts = [];
            $rightStarts = [];
            $rowText = '';
            foreach ($row['parts'] as $part) {
                $rowText .= (string)($part['text'] ?? '');
                $starts = array_column($part['atoms'] ?? [], 'x');
                foreach ($starts === [] ? [$part['xMin']] : $starts as $start) {
                    if ((float)$start < $split) {
                        $leftStarts[] = (float)$start;
                    } else {
                        $rightStarts[] = (float)$start;
                    }
                }
            }
            $sides[$rowIndex] = match (true) {
                mb_strlen(trim($rowText)) <= 2 => 'none',
                $leftStarts !== [] && $rightStarts !== [] => 'both',
                $leftStarts !== [] => 'left',
                $rightStarts !== [] => 'right',
                default => 'none',
            };
        }

        // Use the local line-gap estimate, but cap it so deliberately separated
        // card/caption bands cannot collapse into one page-wide column block.
        $expansionGap = max(42.0, min(80.0, $regionGap * 1.75));
        $components = ['left' => [], 'right' => []];
        foreach (['left', 'right'] as $side) {
            $current = [];
            $previousSideIndex = null;
            foreach ($rows as $rowIndex => $row) {
                if (!in_array($sides[$rowIndex], [$side, 'both'], true)) {
                    continue;
                }
                $gap = $previousSideIndex === null
                    ? 0.0
                    : (float)$rows[$previousSideIndex]['y'] - (float)$row['y'];
                if ($current !== [] && $gap > $expansionGap) {
                    if (count($current) >= 3) {
                        $components[$side][] = $current;
                    }
                    $current = [];
                }
                $current[] = $rowIndex;
                $previousSideIndex = $rowIndex;
            }
            if (count($current) >= 3) {
                $components[$side][] = $current;
            }
        }

        $best = null;
        foreach ($components['left'] as $left) {
            $leftTop = (float)$rows[min($left)]['y'];
            $leftBottom = (float)$rows[max($left)]['y'];
            foreach ($components['right'] as $right) {
                $rightTop = (float)$rows[min($right)]['y'];
                $rightBottom = (float)$rows[max($right)]['y'];
                $overlap = max(0.0, min($leftTop, $rightTop) - max($leftBottom, $rightBottom));
                $shorterSpan = max(
                    1.0,
                    min($leftTop - $leftBottom, $rightTop - $rightBottom),
                );
                if ($overlap / $shorterSpan < 0.45) {
                    continue;
                }
                $score = $overlap * min(count($left), count($right));
                if ($best === null || $score > $best['score']) {
                    $best = [
                        'start' => min(min($left), min($right)),
                        'end' => max(max($left), max($right)),
                        'score' => $score,
                    ];
                }
            }
        }

        if ($best === null) {
            return null;
        }

        // A sizeable gap after both columns have participated denotes a new
        // section only when the remainder becomes one-sided or starts a
        // repeated three-column band. This preserves deliberately offset
        // two-column designs with larger paragraph gaps while preventing a
        // following card grid from being swallowed by the safeguard.
        for ($index = $best['start'] + 1; $index <= $best['end']; $index++) {
            $gap = (float)$rows[$index - 1]['y'] - (float)$rows[$index]['y'];
            if ($gap <= $regionGap) {
                continue;
            }
            $before = array_unique(array_filter(
                array_slice($sides, $best['start'], $index - $best['start']),
                static fn (string $side): bool => $side !== 'none',
            ));
            $after = array_unique(array_filter(
                array_slice($sides, $index, $best['end'] - $index + 1),
                static fn (string $side): bool => $side !== 'none',
            ));
            $beforeHasBothSides = in_array('both', $before, true)
                || (in_array('left', $before, true) && in_array('right', $before, true));
            $afterIsOneSided = count($after) === 1
                && in_array($after[0], ['left', 'right'], true);
            if ($beforeHasBothSides
                && (
                    $afterIsOneSided
                    || $this->startsThreeColumnBand($rows, $index, $pageWidth)
                    || $this->startsDetachedLayoutSection($rows, $index, $regionGap)
                )
            ) {
                $best['end'] = $index - 1;
                break;
            }
        }

        // Locate the first genuine paired column row, then retain any
        // preceding one-sided column tail only until a page-wide heading is
        // encountered. Without this boundary, a title/subtitle above the
        // columns is swallowed by the asymmetric range and split between the
        // left and right reading-order streams.
        $pairedStart = null;
        for ($index = $best['start']; $index <= $best['end']; $index++) {
            if ($sides[$index] === 'both'
                && !$this->isFullWidthRow($rows[$index], $split, $pageWidth)
                && $this->hasGenuineColumnGutter($rows[$index], $split, $pageWidth)
            ) {
                $pairedStart = $index;
                break;
            }
        }
        if ($pairedStart !== null) {
            $trimmedStart = $pairedStart;
            for ($index = $pairedStart - 1; $index >= $best['start']; $index--) {
                $gap = (float)$rows[$index]['y'] - (float)$rows[$index + 1]['y'];
                if ($gap > $expansionGap
                    || $this->isFullWidthRow($rows[$index], $split, $pageWidth)
                    || ($sides[$index] === 'both'
                        && $gap > max(16.0, min(24.0, $regionGap * 0.65)))
                ) {
                    break;
                }
                $trimmedStart = $index;
            }
            $best['start'] = $trimmedStart;
        }

        return ['start' => $best['start'], 'end' => $best['end']];
    }

    /**
     * Detects a short heading isolated by whitespace from both the preceding
     * content and the layout band that follows it. Such a heading is a hard
     * section boundary: an asymmetric prose-column safeguard must never pull
     * a following card grid or a differently structured column block into the
     * preceding region.
     *
     * @param array<int, array{y: float, parts: array<int, array<string, mixed>>}> $rows
     */
    private function startsDetachedLayoutSection(array $rows, int $index, float $regionGap): bool
    {
        if (!isset($rows[$index + 1]) || count($rows[$index]['parts']) !== 1) {
            return false;
        }

        $heading = $rows[$index]['parts'][0];
        $headingText = trim((string)($heading['text'] ?? ''));
        if ($headingText === '' || mb_strlen($headingText) > 80) {
            return false;
        }

        $gapAfter = (float)$rows[$index]['y'] - (float)$rows[$index + 1]['y'];
        if ($gapAfter < max(18.0, $regionGap * 0.75)) {
            return false;
        }

        // A detached heading immediately followed by several horizontally
        // separated objects introduces a new grid or column band even when
        // the PDF uses the same nominal font size for heading and body copy.
        if (count($rows[$index + 1]['parts']) >= 2) {
            return true;
        }

        $headingFontSize = max(array_map(
            static fn (array $atom): float => (float)($atom['verticalScale'] ?? $atom['fontSize'] ?? 10.0),
            ($heading['atoms'] ?? []) ?: [['fontSize' => 10.0]],
        ));
        $followingFontSizes = [];
        foreach (array_slice($rows, $index + 1, 3) as $row) {
            foreach ($row['parts'] as $part) {
                foreach (($part['atoms'] ?? []) ?: [['fontSize' => 10.0]] as $atom) {
                    $followingFontSizes[] = (float)($atom['verticalScale'] ?? $atom['fontSize'] ?? 10.0);
                }
            }
        }

        return $followingFontSizes === [] || $headingFontSize >= max($followingFontSizes) * 1.05;
    }

    /**
     * @param array{parts: array<int, array<string, mixed>>} $row
     */
    private function hasGenuineColumnGutter(array $row, float $split, float $pageWidth): bool
    {
        $partition = $this->layoutRenderer->partitionRowAtSplit($row, $split);
        if ($partition['left'] === [] || $partition['right'] === []) {
            return false;
        }

        $leftEdge = max(array_map(
            static fn (array $part): float => (float)$part['xMax'],
            $partition['left'],
        ));
        $rightEdge = min(array_map(
            static fn (array $part): float => (float)$part['xMin'],
            $partition['right'],
        ));

        return $rightEdge - $leftEdge >= max(12.0, $pageWidth * 0.03);
    }

    /**
     * @param array<int, array{parts: array<int, array<string, mixed>>}> $rows
     */
    private function startsThreeColumnBand(array $rows, int $start, float $pageWidth): bool
    {
        $minimumGutter = max(18.0, $pageWidth * 0.055);
        $threePartRows = 0;
        foreach (array_slice($rows, $start, 8) as $row) {
            $parts = $row['parts'];
            usort($parts, static fn (array $left, array $right): int => $left['xMin'] <=> $right['xMin']);
            $wideGaps = 0;
            for ($index = 1, $count = count($parts); $index < $count; $index++) {
                if ((float)$parts[$index]['xMin'] - (float)$parts[$index - 1]['xMax'] >= $minimumGutter) {
                    $wideGaps++;
                }
            }
            if ($wideGaps >= 2 && ++$threePartRows >= 3) {
                return true;
            }
        }

        return false;
    }

    /**
     * A shorter parallel column may end while the other side continues. The
     * remaining lines belong to the existing column when they keep its start
     * anchor and typography; their estimated width must not turn them into a
     * new full-width band.
     *
     * @param array<int, array{y: float, parts: array<int, array<string, mixed>>}> $current
     * @param array{y: float, parts: array<int, array<string, mixed>>} $row
     */
    private function continuesEstablishedColumn(
        array $current,
        array $row,
        float $split,
        float $pageWidth,
    ): bool {
        $stats = $this->columnStats($current, $split);
        if ($stats['leftRows'] < 2 || $stats['rightRows'] < 2) {
            return false;
        }

        $rowAtoms = [];
        foreach ($row['parts'] as $part) {
            array_push($rowAtoms, ...(($part['atoms'] ?? []) ?: [[
                'x' => $part['xMin'],
                'fontSize' => 10.0,
            ]]));
        }
        if ($rowAtoms === []) {
            return false;
        }
        $rowIsLeft = array_reduce(
            $rowAtoms,
            static fn (bool $onlyLeft, array $atom): bool => $onlyLeft && (float)$atom['x'] < $split,
            true,
        );
        $rowIsRight = array_reduce(
            $rowAtoms,
            static fn (bool $onlyRight, array $atom): bool => $onlyRight && (float)$atom['x'] >= $split,
            true,
        );
        if (!$rowIsLeft && !$rowIsRight) {
            return false;
        }

        $sideStarts = [];
        $sideFontSizes = [];
        foreach ($current as $currentRow) {
            foreach ($currentRow['parts'] as $part) {
                foreach (($part['atoms'] ?? []) ?: [[
                    'x' => $part['xMin'],
                    'fontSize' => 10.0,
                ]] as $atom) {
                    $isSameSide = $rowIsLeft
                        ? (float)$atom['x'] < $split
                        : (float)$atom['x'] >= $split;
                    if ($isSameSide) {
                        $sideStarts[] = (float)$atom['x'];
                        $sideFontSizes[] = (float)($atom['fontSize'] ?? 10.0);
                    }
                }
            }
        }
        if ($sideStarts === []) {
            return false;
        }

        $rowStart = min(array_map(static fn (array $atom): float => (float)$atom['x'], $rowAtoms));
        $rowFontSize = max(array_map(
            static fn (array $atom): float => (float)($atom['fontSize'] ?? 10.0),
            $rowAtoms,
        ));
        $anchorTolerance = max(12.0, $pageWidth * 0.04);
        $nearestAnchorDistance = min(array_map(
            static fn (float $start): float => abs($start - $rowStart),
            $sideStarts,
        ));

        return $nearestAnchorDistance <= $anchorTolerance
            && $rowFontSize <= max($sideFontSizes) * 1.35;
    }

    /** @param array{parts: array<int, array{xMin: float, xMax: float}>} $row */
    private function isFullWidthRow(array $row, float $split, float $pageWidth): bool
    {
        if (count($row['parts']) !== 1) {
            return false;
        }
        $part = $row['parts'][0];
        $partWidth = (float)$part['xMax'] - (float)$part['xMin'];
        $partCenter = ((float)$part['xMin'] + (float)$part['xMax']) / 2;
        // Centred one-character markers (for example an information icon)
        // introduce a page-wide callout rather than extending either prose
        // column. Classifying them as spanning keeps the following callout in
        // its own region.
        if (mb_strlen(trim((string)($part['text'] ?? ''))) <= 2
            && $partWidth <= $pageWidth * 0.08
            && abs($partCenter - $split) <= $pageWidth * 0.08
        ) {
            return true;
        }
        $atomStarts = array_map('floatval', array_column($part['atoms'] ?? [], 'x'));
        sort($atomStarts);
        for ($index = 1, $count = count($atomStarts); $index < $count; $index++) {
            if ($atomStarts[$index] - $atomStarts[$index - 1] > max(20.0, $pageWidth * 0.08)) {
                return false;
            }
        }
        return $part['xMin'] < $split
            && $part['xMax'] > $split + max(20.0, $pageWidth * 0.06)
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
                return $centralAnchors[0]['x'] - $this->columnSplitInset($pageWidth);
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

        return $best === null ? 0.0 : $best - $this->columnSplitInset($pageWidth);
    }

    private function columnSplitInset(float $pageWidth): float
    {
        // Right-column headings and bullet marks are often optically aligned
        // a few points left of the prose anchor. Keep the split safely inside
        // the gutter so those objects stay with their column.
        return max(12.0, $pageWidth * 0.025);
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
