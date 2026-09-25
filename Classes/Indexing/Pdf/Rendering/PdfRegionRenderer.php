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

namespace Madj2k\AiAssistantPremium\Indexing\Pdf\Rendering;

use Madj2k\AiAssistantPremium\Indexing\Pdf\Analysis\PdfColumnDetector;
use Madj2k\AiAssistantPremium\Indexing\Pdf\Analysis\PdfNestedLayoutAnalyzer;
use Madj2k\AiAssistantPremium\Indexing\Pdf\Analysis\PdfRegionClassifier;
use Madj2k\AiAssistantPremium\Indexing\Pdf\Analysis\PdfRegionSegmenter;
use Madj2k\AiAssistantPremium\Indexing\Pdf\Geometry\PdfPositionedTextReader;

/**
 * Class PdfRegionRenderer
 *
 * Renders classified PDF regions recursively in their reconstructed reading order.
 *
 * @phpstan-import-type PdfVisualRowList from PdfPositionedTextReader
 * @phpstan-import-type PdfRegionClassification from PdfRegionClassifier
 *
 * @author Maximilian Fäßler <maximilian@faesslerweb.de>
 * @copyright Steffen Kroggel <developer@steffenkroggel.de>, Maximilian Fäßler <maximilian@faesslerweb.de>
 * @package Madj2k\AiAssistantPremium
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License, version 3
 */
final readonly class PdfRegionRenderer
{
    /**
     * Constructor.
     *
     * @param PdfRegionSegmenter $regionSegmenter Segmenter for horizontal layout regions.
     * @param PdfRegionClassifier $regionClassifier Classifier for segmented regions.
     * @param PdfNestedLayoutAnalyzer $nestedLayoutAnalyzer Analyzer for nested column sides.
     * @param PdfColumnDetector $columnDetector Detector for nested column gutters.
     * @param PdfLayoutRenderer $layoutRenderer Renderer for rows and classified columns.
     */
    public function __construct(
        private PdfRegionSegmenter $regionSegmenter = new PdfRegionSegmenter(),
        private PdfRegionClassifier $regionClassifier = new PdfRegionClassifier(),
        private PdfNestedLayoutAnalyzer $nestedLayoutAnalyzer = new PdfNestedLayoutAnalyzer(),
        private PdfColumnDetector $columnDetector = new PdfColumnDetector(),
        private PdfLayoutRenderer $layoutRenderer = new PdfLayoutRenderer(),
    ) {
    }

    /**
     * Segments a page into horizontal bands before choosing the reading order
     * for each band. Full-width headings stay in visual order, prose columns
     * are read column by column, and genuine cross-page tables remain row-wise.
     *
     * @param array $rows Visual text rows.
     * @phpstan-param PdfVisualRowList $rows
     * @param float $split X coordinate of the primary column gutter.
     * @param float $pageWidth Width of the occupied text area.
     * @param int $depth Current nested-column recursion depth.
     * @return array{text: string, hasColumns: bool, hasTables: bool, hasPlain: bool, maxColumnCount: int, tableRowCount: int} Rendered text and aggregate region statistics.
     */
    public function render(
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

        foreach ($this->regionSegmenter->segment($rows, $split, $pageWidth, true) as $region) {
            $classification = $this->regionClassifier->classify($region, $split, $pageWidth, true);
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
     * Renders an outer column and then analyses each side again. This handles
     * layouts such as a large callout beside a two-column card grid without
     * introducing image- or document-specific rules.
     *
     * @param array $rows Rows in the outer column region.
     * @phpstan-param PdfVisualRowList $rows
     * @param float $split X coordinate of the outer gutter.
     * @param int $depth Current recursive nesting depth.
     * @return string Text rendered in nested column reading order.
     */
    private function renderNestedColumnRegion(array $rows, float $split, int $depth): string
    {
        $sides = $this->nestedLayoutAnalyzer->partitionAtSplit($rows, $split);
        $blocks = [];
        foreach ([$sides['left'], $sides['right']] as $sideRows) {
            if ($sideRows === []) {
                continue;
            }
            $sideWidth = max(1.0, $this->columnDetector->maximumX($sideRows) - $this->columnDetector->minimumX($sideRows));
            $sideColumns = $this->columnDetector->detectNestedColumns($sideRows, $sideWidth);
            if ($sideColumns['count'] > 1) {
                $blocks[] = $this->render(
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


}
