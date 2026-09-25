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

namespace Madj2k\AiAssistantPremium\Backend\PdfDiagnostics;

use Madj2k\AiAssistantPremium\Indexing\Pdf\Analysis\PdfColumnDetector;
use Madj2k\AiAssistantPremium\Indexing\Pdf\Analysis\PdfNestedLayoutAnalyzer;
use Madj2k\AiAssistantPremium\Indexing\Pdf\Analysis\PdfRegionClassifier;
use Madj2k\AiAssistantPremium\Indexing\Pdf\Analysis\PdfRegionSegmenter;
use Madj2k\AiAssistantPremium\Indexing\Pdf\Geometry\PdfPositionedTextReader;

/**
 * Class PdfLayoutDiagnosticsProvider
 *
 * Builds backend layout-region diagnostics from the production analysis components.
 *
 * @phpstan-import-type PdfPositionedTextEntryList from PdfPositionedTextReader
 * @phpstan-import-type PdfVisualRowList from PdfPositionedTextReader
 *
 * @author Maximilian Fäßler <maximilian@faesslerweb.de>
 * @copyright Steffen Kroggel <developer@steffenkroggel.de>, Maximilian Fäßler <maximilian@faesslerweb.de>
 * @package Madj2k\AiAssistantPremium
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License, version 3
 */
final readonly class PdfLayoutDiagnosticsProvider
{
    private const string LLL_PREFIX =
        'LLL:EXT:ai_assistant_premium/Resources/Private/Language/locallang_pdf_diagnostics.xlf:';

    /**
     * Constructor.
     *
     * @param PdfPositionedTextReader $positionedTextReader Reader for visual PDF rows.
     * @param PdfColumnDetector $columnDetector Detector for the page-level gutter.
     * @param PdfRegionSegmenter $regionSegmenter Segmenter for horizontal layout regions.
     * @param PdfRegionClassifier $regionClassifier Shared production region classifier.
     * @param PdfNestedLayoutAnalyzer $nestedLayoutAnalyzer Analyzer for diagnostic nested gutters.
     */
    public function __construct(
        private PdfPositionedTextReader $positionedTextReader = new PdfPositionedTextReader(),
        private PdfColumnDetector $columnDetector = new PdfColumnDetector(),
        private PdfRegionSegmenter $regionSegmenter = new PdfRegionSegmenter(),
        private PdfRegionClassifier $regionClassifier = new PdfRegionClassifier(),
        private PdfNestedLayoutAnalyzer $nestedLayoutAnalyzer = new PdfNestedLayoutAnalyzer(),
    ) {
    }

    /**
     * Returns the horizontal regions used by the reading-order analysis.
     * Coordinates remain in PDF user space and are converted for the backend
     * preview by the diagnostic service.
     *
     * @param array $positionedText Smalot getDataTm() output.
     * @phpstan-param PdfPositionedTextEntryList $positionedText
     * @return array<int, array<string, int|float|string|array<int, float>>> Classified regions with PDF-space bounds.
     */
    public function diagnose(array $positionedText): array
    {
        $rows = $this->positionedTextReader->read($positionedText);
        if ($rows === []) {
            return [];
        }

        $pageWidth = max(1.0, $this->columnDetector->maximumX($rows) - $this->columnDetector->minimumX($rows));
        $columns = $this->columnDetector->detect($rows, $pageWidth);
        $split = $columns['count'] > 1
            ? $columns['split']
            : $this->columnDetector->minimumX($rows) + $pageWidth / 2;
        $result = [];

        foreach ($this->regionSegmenter->segment(
            $rows,
            $split,
            $pageWidth,
            $columns['count'] > 1,
        ) as $region) {
            $classification = $this->regionClassifier->classify(
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
                    'columns' => self::LLL_PREFIX . 'layout.type.columns',
                    'column-blocks' => self::LLL_PREFIX . 'layout.type.columnBlocks',
                    'multi-column-blocks' => self::LLL_PREFIX . 'layout.type.multiColumnBlocks',
                    'nested-columns' => self::LLL_PREFIX . 'layout.type.nestedColumns',
                    'table' => self::LLL_PREFIX . 'layout.type.table',
                    'side-by-side-tables' => self::LLL_PREFIX . 'layout.type.sideBySideTables',
                    default => self::LLL_PREFIX . 'layout.type.fullWidth',
                },
                'columnCount' => $classification['columnCount'],
                'columnSplits' => $classification['columnCount'] > 2
                    ? $classification['columnSplits']
                    : ($classification['hasNestedColumns']
                        ? $this->nestedLayoutAnalyzer->detectSplits($region, $split)
                        : ($classification['isColumnRegion'] ? [$split] : [])),
                'tableRowCount' => $classification['tableRowCount'],
                ...$bounds,
            ];
        }

        return $result;
    }


    /**
     * Calculates the enclosing PDF-space bounds of a layout region.
     *
     * @param array $region Rows belonging to the region.
     * @phpstan-param PdfVisualRowList $region
     * @return array{xMin: float, xMax: float, yBottom: float, yTop: float} Region bounds in PDF user space.
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
                    static fn (array $atom): float => $atom['fontSize'],
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
}
