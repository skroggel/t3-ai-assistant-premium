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
use Madj2k\AiAssistantPremium\Indexing\Pdf\Geometry\PdfVisualRowPartitioner;

/**
 * Class PdfRegionSegmenter
 *
 * Splits visual PDF rows into horizontal regions with consistent layout modes.
 *
 * @phpstan-import-type PdfVisualRow from PdfPositionedTextReader
 * @phpstan-import-type PdfVisualRowList from PdfPositionedTextReader
 *
 * @author Maximilian Fäßler <maximilian@faesslerweb.de>
 * @copyright Steffen Kroggel <developer@steffenkroggel.de>, Maximilian Fäßler <maximilian@faesslerweb.de>
 * @package Madj2k\AiAssistantPremium
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License, version 3
 */
final readonly class PdfRegionSegmenter
{
    /**
     * Constructor.
     *
     * @param PdfVisualRowPartitioner $visualRowPartitioner Partitioner for rows crossing a column gutter.
     */
    public function __construct(
        private PdfVisualRowPartitioner $visualRowPartitioner = new PdfVisualRowPartitioner(),
    ) {
    }

    /**
     * Splits visual rows into horizontal regions with internally consistent layout modes.
     *
     * @param array $rows Visual text rows.
     * @phpstan-param PdfVisualRowList $rows
     * @param float $split X coordinate of the primary column gutter.
     * @param float $pageWidth Width of the occupied text area.
     * @param bool $separateSpanningBands Whether full-width rows should form separate regions.
     * @return array Horizontal layout regions.
     * @phpstan-return array<int, PdfVisualRowList>
     */
    public function segment(
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
     * @param array $current Current full-width region.
     * @phpstan-param PdfVisualRowList $current
     * @param array{y: float, parts: array<int, array<string, mixed>>} $row Candidate continuation row.
     * @param float $pageWidth Width of the occupied text area.
     * @return bool Whether the row continues the established full-width band.
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
     * @param array $current Current short heading region.
     * @phpstan-param PdfVisualRowList $current
     * @param array{y: float, parts: array<int, array<string, mixed>>} $row Candidate full-width content row.
     * @param float $pageWidth Width of the occupied text area.
     * @return bool Whether the heading and row form one full-width band.
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
     * @param array $rows Visual text rows.
     * @phpstan-param PdfVisualRowList $rows
     * @param float $split X coordinate of the candidate column gutter.
     * @param float $pageWidth Width of the occupied text area.
     * @param float $regionGap Vertical distance that separates layout regions.
     * @return array{start: int, end: int}|null Inclusive row range, or null when no asymmetric block exists.
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
                $rowText .= $part['text'];
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
     * @param array $rows Visual text rows.
     * @phpstan-param PdfVisualRowList $rows
     * @param int $index Index of the candidate heading row.
     * @param float $regionGap Vertical distance that separates layout regions.
     * @return bool Whether the row starts a detached layout section.
     */
    private function startsDetachedLayoutSection(array $rows, int $index, float $regionGap): bool
    {
        if (!isset($rows[$index + 1]) || count($rows[$index]['parts']) !== 1) {
            return false;
        }

        $heading = $rows[$index]['parts'][0];
        $headingText = trim($heading['text']);
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
            static fn (array $atom): float => $atom['verticalScale'] ?? $atom['fontSize'],
            ($heading['atoms'] ?? []) ?: [['fontSize' => 10.0]],
        ));
        $followingFontSizes = [];
        foreach (array_slice($rows, $index + 1, 3) as $row) {
            foreach ($row['parts'] as $part) {
                foreach (($part['atoms'] ?? []) ?: [['fontSize' => 10.0]] as $atom) {
                    $followingFontSizes[] = $atom['verticalScale'] ?? $atom['fontSize'];
                }
            }
        }

        return $followingFontSizes === [] || $headingFontSize >= max($followingFontSizes) * 1.05;
    }


    /**
     * Checks whether a row contains content on both sides of a visibly empty gutter.
     *
     * @param array $row Row to inspect.
     * @phpstan-param PdfVisualRow $row
     * @param float $split X coordinate of the candidate gutter.
     * @param float $pageWidth Width of the occupied text area.
     * @return bool Whether the row provides genuine gutter evidence.
     */
    public function hasGenuineColumnGutter(array $row, float $split, float $pageWidth): bool
    {
        $partition = $this->visualRowPartitioner->partitionAtSplit($row, $split);
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
     * Checks whether the upcoming rows begin a persistent three-column band.
     *
     * @param array $rows Visual text rows.
     * @phpstan-param PdfVisualRowList $rows
     * @param int $start Index at which the candidate band starts.
     * @param float $pageWidth Width of the occupied text area.
     * @return bool Whether at least three upcoming rows expose two wide gutters.
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
     * @param array $current Current column region.
     * @phpstan-param PdfVisualRowList $current
     * @param array{y: float, parts: array<int, array<string, mixed>>} $row Candidate continuation row.
     * @param float $split X coordinate of the established gutter.
     * @param float $pageWidth Width of the occupied text area.
     * @return bool Whether the row continues either established column.
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
                        $sideFontSizes[] = $atom['fontSize'];
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


    /**
     * Determines whether a single visual row spans the page-wide content area.
     *
     * @param array $row Row to classify.
     * @phpstan-param PdfVisualRow $row
     * @param float $split X coordinate of the primary gutter.
     * @param float $pageWidth Width of the occupied text area.
     * @return bool Whether the row should be treated as full-width content.
     */
    public function isFullWidthRow(array $row, float $split, float $pageWidth): bool
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
        if (mb_strlen(trim($part['text'])) <= 2
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
     * Summarizes how strongly a region participates on both sides of a gutter.
     *
     * @param array $rows Rows to inspect.
     * @phpstan-param PdfVisualRowList $rows
     * @param float $split X coordinate of the candidate gutter.
     * @return array{leftRows: int, rightRows: int, overlapRatio: float} Per-side row counts and shared-row ratio.
     */
    public function columnStats(array $rows, float $split): array
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


}
