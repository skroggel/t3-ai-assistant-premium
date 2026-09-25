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

namespace Madj2k\AiAssistantPremium\Indexing\Pdf;

use Madj2k\AiAssistantPremium\Indexing\Pdf\Analysis\PdfColumnDetector;
use Madj2k\AiAssistantPremium\Indexing\Pdf\Analysis\PdfTableDetector;
use Madj2k\AiAssistantPremium\Indexing\Pdf\DTO\PdfLayoutAnalysis;
use Madj2k\AiAssistantPremium\Indexing\Pdf\Geometry\PdfPositionedTextReader;
use Madj2k\AiAssistantPremium\Indexing\Pdf\Rendering\PdfLayoutRenderer;
use Madj2k\AiAssistantPremium\Indexing\Pdf\Rendering\PdfRegionRenderer;

/**
 * Class PdfPageLayoutAnalyzer
 *
 * Reconstructs page reading order through the shared PDF layout components.
 *
 * @phpstan-import-type PdfVisualRowList from PdfPositionedTextReader
 * @phpstan-import-type PdfPositionedTextEntryList from PdfPositionedTextReader
 *
 * @author Maximilian Fäßler <maximilian@faesslerweb.de>
 * @copyright Steffen Kroggel <developer@steffenkroggel.de>, Maximilian Fäßler <maximilian@faesslerweb.de>
 * @package Madj2k\AiAssistantPremium
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License, version 3
 */
final readonly class PdfPageLayoutAnalyzer
{
    /**
     * Constructor.
     *
     * @param PdfPositionedTextReader $positionedTextReader Reader that converts PDF text objects into visual rows.
     * @param PdfLayoutRenderer $layoutRenderer Renderer that serializes ordinary visual rows.
     * @param PdfColumnDetector $columnDetector Detector for page-level columns.
     * @param PdfTableDetector $tableDetector Detector for page-wide tables.
     * @param PdfRegionRenderer $regionRenderer Renderer for classified horizontal regions.
     */
    public function __construct(
        private PdfPositionedTextReader $positionedTextReader = new PdfPositionedTextReader(),
        private PdfLayoutRenderer $layoutRenderer = new PdfLayoutRenderer(),
        private PdfColumnDetector $columnDetector = new PdfColumnDetector(),
        private PdfTableDetector $tableDetector = new PdfTableDetector(),
        private PdfRegionRenderer $regionRenderer = new PdfRegionRenderer(),
    ) {
    }

    /**
     * Reconstructs readable page text and classifies the detected page layout.
     *
     * @param array $positionedText Smalot getDataTm() output.
     * @phpstan-param PdfPositionedTextEntryList $positionedText
     * @return PdfLayoutAnalysis Reconstructed text and layout metadata.
     */
    public function analyze(array $positionedText): PdfLayoutAnalysis
    {
        $segments = $this->positionedTextReader->read($positionedText);
        if ($segments === []) {
            return new PdfLayoutAnalysis('', 'empty', 0, 0, 0.0);
        }
        $requiresPositionedText = $this->requiresPositionedText($segments);
        $pageWidth = max(1.0, $this->columnDetector->maximumX($segments) - $this->columnDetector->minimumX($segments));
        $table = $this->tableDetector->detect($segments, $pageWidth);
        $columns = $this->columnDetector->detect($segments, $pageWidth);

        if ($columns['count'] > 1) {
            // Page-level column evidence enables segmentation, but every
            // horizontal band is classified again before its reading order is chosen.
            $regional = $this->regionRenderer->render($segments, $columns['split'], $pageWidth);
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
     * @param array $rows Visual text rows.
     * @phpstan-param PdfVisualRowList $rows
     * @return bool Whether geometry-based text reconstruction is required.
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
                static fn (array $atom): float => $atom['verticalScale'],
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


}
