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
 * Class PdfTableDetector
 *
 * Detects tabular regions and independent side-by-side tables.
 *
 * @phpstan-import-type PdfVisualRowList from PdfPositionedTextReader
 *
 * @author Maximilian Fäßler <maximilian@faesslerweb.de>
 * @copyright Steffen Kroggel <developer@steffenkroggel.de>, Maximilian Fäßler <maximilian@faesslerweb.de>
 * @package Madj2k\AiAssistantPremium
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License, version 3
 */
final readonly class PdfTableDetector
{
    private const int MIN_TABLE_ROWS = 3;

    /**
     * Constructor.
     *
     * @param PdfColumnDetector $columnDetector Detector providing shared text-anchor geometry.
     * @param PdfVisualRowPartitioner $visualRowPartitioner Partitioner for rows crossing a table gutter.
     */
    public function __construct(
        private PdfColumnDetector $columnDetector = new PdfColumnDetector(),
        private PdfVisualRowPartitioner $visualRowPartitioner = new PdfVisualRowPartitioner(),
    ) {
    }

    /**
     * Detects two independent side-by-side tables by requiring repeated cell
     * starts on both sides of the gutter. A prose column next to a bullet list
     * therefore remains prose instead of being serialized as table cells.
     *
     * @param array $rows Rows in the candidate region.
     * @phpstan-param PdfVisualRowList $rows
     * @param float $split X coordinate of the gutter separating both tables.
     * @return bool Whether stable cell anchors identify independent tables on both sides.
     */
    public function hasIndependentSideTables(array $rows, float $split): bool
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
            $sideWidth = max(1.0, $this->columnDetector->maximumX($sideRows) - $this->columnDetector->minimumX($sideRows));
            if (!$this->detect($sideRows, $sideWidth)['isTable']) {
                return false;
            }
        }

        return true;
    }


    /**
     * Detects table structure from repeated cell starts and row fill characteristics.
     *
     * @param array $rows Rows to classify.
     * @phpstan-param PdfVisualRowList $rows
     * @param float $pageWidth Width of the occupied text area.
     * @return array{isTable: bool, rowCount: int, confidence: float} Table classification and confidence.
     */
    public function detect(array $rows, float $pageWidth): array
    {
        $multiPartRows = array_values(array_filter(
            $rows,
            static fn (array $row): bool => count($row['parts']) >= 2 && count($row['parts']) <= 8,
        ));
        if (count($multiPartRows) < self::MIN_TABLE_ROWS) {
            return ['isTable' => false, 'rowCount' => 0, 'confidence' => 0.0];
        }

        $tolerance = max(3.0, min(8.0, $pageWidth * 0.012));
        $anchors = $this->columnDetector->clusterStarts($multiPartRows, $tolerance);
        $stableAnchors = array_values(array_filter(
            $anchors,
            static fn (array $anchor): bool => $anchor['count'] >= self::MIN_TABLE_ROWS,
        ));

        if (count($stableAnchors) < 2) {
            return ['isTable' => false, 'rowCount' => 0, 'confidence' => 0.0];
        }

        $tableRowMatchCountList = array_map(static function (array $row) use ($stableAnchors, $tolerance): int {
            $matchCount = 0;
            foreach ($row['parts'] as $part) {
                foreach ($stableAnchors as $anchor) {
                    if (abs($part['xMin'] - $anchor['x']) <= $tolerance) {
                        $matchCount++;
                        break;
                    }
                }
            }
            return $matchCount;
        }, $multiPartRows);
        $fillRatios = [];
        $cellLengths = [];
        foreach ($multiPartRows as $rowIndex => $row) {
            if ($tableRowMatchCountList[$rowIndex] >= 2) {
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
        $tableRowCount = count(array_filter(
            $tableRowMatchCountList,
            static fn (int $matchCount): bool => $matchCount >= 2,
        ));
        $medianFill = $fillRatios === [] ? 1.0 : $fillRatios[intdiv(count($fillRatios), 2)];
        $medianCellLength = $cellLengths === [] ? 0 : $cellLengths[intdiv(count($cellLengths), 2)];
        $coverage = $tableRowCount / max(1, count($multiPartRows));
        $isTable = $tableRowCount >= self::MIN_TABLE_ROWS
            && $coverage >= 0.45
            && (
                count($stableAnchors) >= 4
                || ($medianFill < 0.52 && $medianCellLength <= 24)
            );
        $confidence = min(0.99, 0.45 + $coverage * 0.3 + min(0.2, count($stableAnchors) * 0.04));

        return [
            'isTable' => $isTable,
            'rowCount' => $isTable ? $tableRowCount : 0,
            'confidence' => $isTable ? $confidence : 0.0,
        ];
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
    private function partitionRegionAtSplit(array $rows, float $split): array
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
