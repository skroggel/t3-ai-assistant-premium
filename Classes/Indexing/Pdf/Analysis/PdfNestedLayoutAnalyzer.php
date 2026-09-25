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
 * Class PdfNestedLayoutAnalyzer
 *
 * Detects and partitions repeated column layouts nested inside outer columns.
 *
 * @phpstan-import-type PdfVisualRowList from PdfPositionedTextReader
 *
 * @author Maximilian Fäßler <maximilian@faesslerweb.de>
 * @copyright Steffen Kroggel <developer@steffenkroggel.de>, Maximilian Fäßler <maximilian@faesslerweb.de>
 * @package Madj2k\AiAssistantPremium
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License, version 3
 */
final readonly class PdfNestedLayoutAnalyzer
{
    private const int MIN_COLUMN_LINES = 4;

    /**
     * Constructor.
     *
     * @param PdfVisualRowPartitioner $visualRowPartitioner Partitioner for rows crossing an outer gutter.
     * @param PdfColumnDetector $columnDetector Detector for inner column gutters.
     * @param PdfRegionSegmenter $regionSegmenter Segmenter for repeated inner layout bands.
     */
    public function __construct(
        private PdfVisualRowPartitioner $visualRowPartitioner = new PdfVisualRowPartitioner(),
        private PdfColumnDetector $columnDetector = new PdfColumnDetector(),
        private PdfRegionSegmenter $regionSegmenter = new PdfRegionSegmenter(),
    ) {
    }

    /**
     * Returns the outer gutter plus every validated inner gutter. Diagnostic
     * line boxes need all of them: otherwise atoms from neighbouring nested
     * cards are merged into a visual line that no longer exists in the
     * reconstructed card-by-card reading order.
     *
     * @param array $rows Rows in the outer column region.
     * @phpstan-param PdfVisualRowList $rows
     * @param float $outerSplit X coordinate of the outer column gutter.
     * @return array<int, float> Validated outer and nested gutter coordinates.
     */
    public function detectSplits(array $rows, float $outerSplit): array
    {
        $splits = [$outerSplit];
        foreach ($this->partitionAtSplit($rows, $outerSplit) as $sideRows) {
            if (count($sideRows) < self::MIN_COLUMN_LINES * 2) {
                continue;
            }
            $sideWidth = max(1.0, $this->columnDetector->maximumX($sideRows) - $this->columnDetector->minimumX($sideRows));
            $columns = $this->columnDetector->detectNestedColumns($sideRows, $sideWidth);
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
     * Determines whether either outer column contains a repeated inner column layout.
     *
     * @param array $rows Rows in the outer column region.
     * @phpstan-param PdfVisualRowList $rows
     * @param float $split X coordinate of the outer gutter.
     * @return bool Whether a nested column layout is present.
     */
    public function hasNestedLayout(array $rows, float $split): bool
    {
        foreach ($this->partitionAtSplit($rows, $split) as $sideRows) {
            if (count($sideRows) < self::MIN_COLUMN_LINES * 2) {
                continue;
            }
            $sideWidth = max(1.0, $this->columnDetector->maximumX($sideRows) - $this->columnDetector->minimumX($sideRows));
            $columns = $this->columnDetector->detectNestedColumns($sideRows, $sideWidth);
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
     * @param array $rows Rows on one side of the outer gutter.
     * @phpstan-param PdfVisualRowList $rows
     * @param float $split X coordinate of the candidate inner gutter.
     * @param float $pageWidth Width of the inspected side.
     * @return bool Whether at least two vertically separated two-column bands exist.
     */
    private function hasRepeatedNestedBands(array $rows, float $split, float $pageWidth): bool
    {
        $pairedBands = 0;
        foreach ($this->regionSegmenter->segment($rows, $split, $pageWidth, false) as $band) {
            $stats = $this->regionSegmenter->columnStats($band, $split);
            $rowsWithGenuineGutter = count(array_filter(
                $band,
                fn (array $row): bool => $this->regionSegmenter->hasGenuineColumnGutter($row, $split, $pageWidth),
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
     * Partitions every row into content left and right of a gutter coordinate.
     *
     * @param array $rows Rows to partition.
     * @phpstan-param PdfVisualRowList $rows
     * @param float $split X coordinate of the gutter.
     * @return array Non-empty rows for both sides.
     * @phpstan-return array{left: PdfVisualRowList, right: PdfVisualRowList}
     */
    public function partitionAtSplit(array $rows, float $split): array
    {
        $left = [];
        $right = [];
        foreach ($rows as $row) {
            $partition = $this->visualRowPartitioner->partitionAtSplit($row, $split);
            if ($partition['left'] !== []) {
                $left[] = ['y' => $row['y'], 'parts' => $partition['left']];
            }
            if ($partition['right'] !== []) {
                $right[] = ['y' => $row['y'], 'parts' => $partition['right']];
            }
        }
        return ['left' => $left, 'right' => $right];
    }


}
