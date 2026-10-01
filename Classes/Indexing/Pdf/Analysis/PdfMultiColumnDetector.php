<?php
declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, version 3.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

namespace Madj2k\AiAssistantPremium\Indexing\Pdf\Analysis;

use Madj2k\AiAssistantPremium\Indexing\Pdf\Geometry\PdfPositionedTextReader;

/**
 * Class PdfMultiColumnDetector
 *
 * Detects independent brochure cards and sparse parallel content columns.
 *
 * @phpstan-import-type PdfVisualRowList from PdfPositionedTextReader
 *
 * @author Maximilian Fäßler <maximilian@faesslerweb.de>
 * @copyright Steffen Kroggel <developer@steffenkroggel.de>, Maximilian Fäßler <maximilian@faesslerweb.de>
 * @package Madj2k\AiAssistantPremium
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License, version 3
 */
final readonly class PdfMultiColumnDetector
{
    private const int MIN_COLUMN_LINES = 4;

    /**
     * Constructor.
     *
     * @param PdfPositionedTextReader $positionedTextReader Reader for reconstructing staggered text segments.
     * @param PdfColumnDetector $columnDetector Detector providing shared column geometry.
     */
    public function __construct(
        private PdfPositionedTextReader $positionedTextReader = new PdfPositionedTextReader(),
        private PdfColumnDetector $columnDetector = new PdfColumnDetector(),
    ) {
    }

    /**
     * Detects a compact row of independent brochure cards. It deliberately
     * requires three persistent columns with a shared start and either
     * staggered endings or a visibly detached heading row. A regular table,
     * whose columns normally participate in uniform logical rows, therefore
     * keeps its row-wise representation.
     *
     * @param array $rows Rows to inspect for independent blocks.
     * @phpstan-param PdfVisualRowList $rows
     * @param float $pageWidth Width of the occupied text area.
     * @return array{count: int, splits: array<int, float>} Detected column count and gutter coordinates.
     */
    public function detect(array $rows, float $pageWidth): array
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
        $contentLeft = $this->columnDetector->minimumX($rows);
        $contentRight = $this->columnDetector->maximumX($rows);
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
     * @param array $rows Rows to inspect for atom-level column anchors.
     * @phpstan-param PdfVisualRowList $rows
     * @param float $pageWidth Width of the occupied text area.
     * @return array{count: int, splits: array<int, float>} Detected column count and gutter coordinates.
     */
    private function detectThreeProseColumnsFromAtomAnchors(array $rows, float $pageWidth): array
    {
        $candidateRows = [];
        foreach ($rows as $rowIndex => $row) {
            $atoms = [];
            foreach ($row['parts'] as $part) {
                foreach ($part['atoms'] ?? [] as $atom) {
                    $text = trim($atom['text']);
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
                $anchors[1] - $this->columnDetector->columnSplitInset($pageWidth),
                $anchors[2] - $this->columnDetector->columnSplitInset($pageWidth),
            ],
        ];
    }


    /**
     * Detects three aligned cards from their persistent left edges. Card copy
     * can almost fill its visual column, leaving no sufficiently wide gutter
     * for the whitespace-based detector. Requiring three aligned rows plus a
     * compact or detached heading keeps fragmented prose out of this fallback.
     *
     * @param array $rows Rows to inspect for part-level column anchors.
     * @phpstan-param PdfVisualRowList $rows
     * @param float $pageWidth Width of the occupied text area.
     * @return array{count: int, splits: array<int, float>} Detected column count and gutter coordinates.
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
     * @param array $rows Rows in the candidate card grid.
     * @phpstan-param PdfVisualRowList $rows
     * @param array<int, array<int, bool>> $participation Row participation indexed by column.
     * @return bool Whether the grid begins with a detached shared heading row.
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
     * @param array $rows Rows in the candidate card grid.
     * @phpstan-param PdfVisualRowList $rows
     * @param array<int, array<int, bool>> $participation Row participation indexed by column.
     * @param array<int, float> $splits Candidate gutter coordinates.
     * @return bool Whether each column starts with compact heading-like content.
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
     * Detects short left/right content pairs such as captions below a grid of
     * images. The page-wide column detector must already have supplied a split;
     * this local check additionally requires two genuinely separate objects
     * with a visible gutter on at least one common row.
     *
     * @param array $rows Rows in the candidate region.
     * @phpstan-param PdfVisualRowList $rows
     * @param float $split X coordinate of the candidate gutter.
     * @param float $pageWidth Width of the occupied text area.
     * @return bool Whether the region contains a sparse left/right content pair.
     */
    public function hasPairedSparseBlocks(array $rows, float $split, float $pageWidth): bool
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
     * @param array $rows Rows in the candidate region.
     * @phpstan-param PdfVisualRowList $rows
     * @param float $split X coordinate of the candidate gutter.
     * @param float $pageWidth Width of the occupied text area.
     * @return bool Whether staggered rows form two overlapping parallel blocks.
     */
    public function hasOffsetParallelBlocks(array $rows, float $split, float $pageWidth): bool
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


}
