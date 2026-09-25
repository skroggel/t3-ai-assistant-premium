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
 * Class PdfRegionClassifier
 *
 * Classifies segmented visual rows as prose, columns, nested layouts or tables.
 *
 * @phpstan-import-type PdfVisualRowList from PdfPositionedTextReader
 * @phpstan-type PdfRegionClassification array{
 *     type: string,
 *     isColumnRegion: bool,
 *     hasIndependentTables: bool,
 *     hasNestedColumns: bool,
 *     tableRowCount: int,
 *     columnCount: int,
 *     columnSplits: array<int, float>
 * }
 *
 * @author Maximilian Fäßler <maximilian@faesslerweb.de>
 * @copyright Steffen Kroggel <developer@steffenkroggel.de>, Maximilian Fäßler <maximilian@faesslerweb.de>
 * @package Madj2k\AiAssistantPremium
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License, version 3
 */
final readonly class PdfRegionClassifier
{
    private const int MIN_COLUMN_LINES = 4;

    /**
     * Constructor.
     *
     * @param PdfTableDetector $tableDetector Detector for table-like regions.
     * @param PdfMultiColumnDetector $multiColumnDetector Detector for brochure cards and sparse columns.
     * @param PdfRegionSegmenter $regionSegmenter Segmenter providing regional column statistics.
     * @param PdfColumnDetector $columnDetector Detector for stable column anchors.
     * @param PdfNestedLayoutAnalyzer $nestedLayoutAnalyzer Analyzer for repeated nested layouts.
     */
    public function __construct(
        private PdfTableDetector $tableDetector = new PdfTableDetector(),
        private PdfMultiColumnDetector $multiColumnDetector = new PdfMultiColumnDetector(),
        private PdfRegionSegmenter $regionSegmenter = new PdfRegionSegmenter(),
        private PdfColumnDetector $columnDetector = new PdfColumnDetector(),
        private PdfNestedLayoutAnalyzer $nestedLayoutAnalyzer = new PdfNestedLayoutAnalyzer(),
    ) {
    }

    /**
     * Classifies one horizontal page region from its geometric row structure.
     *
     * @param array $region Rows belonging to the region.
     * @phpstan-param PdfVisualRowList $region
     * @param float $split X coordinate of the primary column gutter.
     * @param float $pageWidth Width of the occupied text area.
     * @param bool $allowColumns Whether page-level evidence permits column classification.
     * @return array{type: string, isColumnRegion: bool, hasIndependentTables: bool, hasNestedColumns: bool, tableRowCount: int, columnCount: int, columnSplits: array<int, float>} Region classification and metrics.
     */
    public function classify(
        array $region,
        float $split,
        float $pageWidth,
        bool $allowColumns,
    ): array {
        $table = $this->tableDetector->detect($region, $pageWidth);
        $multiColumns = $allowColumns
            ? $this->multiColumnDetector->detect($region, $pageWidth)
            : ['count' => 1, 'splits' => []];
        $columnStats = $this->regionSegmenter->columnStats($region, $split);
        $hasIndependentTables = $allowColumns && $this->tableDetector->hasIndependentSideTables($region, $split);
        $hasDenseColumnEvidence = $allowColumns
            && $columnStats['leftRows'] >= 2
            && $columnStats['rightRows'] >= 2
            && $columnStats['leftRows'] + $columnStats['rightRows'] >= self::MIN_COLUMN_LINES * 2
            && $this->columnDetector->hasStableColumnAnchors($region, $split, $pageWidth);
        $hasPairedSparseBlocks = $allowColumns
            && (
                $this->multiColumnDetector->hasPairedSparseBlocks($region, $split, $pageWidth)
                || $this->multiColumnDetector->hasOffsetParallelBlocks($region, $split, $pageWidth)
            );
        $isMultiColumnRegion = $multiColumns['count'] > 2;
        $isColumnRegion = $isMultiColumnRegion || $hasDenseColumnEvidence || $hasPairedSparseBlocks;
        $fullWidthRows = count(array_filter(
            $region,
            fn (array $row): bool => $this->regionSegmenter->isFullWidthRow($row, $split, $pageWidth),
        ));
        $isPredominantlyFullWidth = $fullWidthRows / max(1, count($region)) >= 0.65;
        $isColumnRegion = $isColumnRegion && ($isMultiColumnRegion || !$isPredominantlyFullWidth);
        $isSingleWideTable = $table['isTable'] && $columnStats['overlapRatio'] >= 0.7;
        $isColumnRegion = $isColumnRegion && (!$isSingleWideTable || $isMultiColumnRegion);
        $hasNestedColumns = $isColumnRegion
            && !$isMultiColumnRegion
            && !$hasIndependentTables
            && $this->nestedLayoutAnalyzer->hasNestedLayout($region, $split);

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


}
