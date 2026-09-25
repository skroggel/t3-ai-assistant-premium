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

use Madj2k\AiAssistantPremium\Indexing\Pdf\DTO\PdfPageExtractionResult;
use Madj2k\AiAssistantPremium\Indexing\Pdf\Geometry\PdfPositionedTextReader;
use Smalot\PdfParser\Page;

/**
 * Class PdfPageTextExtractor
 *
 * Selects native or geometry-based text extraction for one PDF page.
 *
 * @phpstan-import-type PdfPositionedTextEntryList from PdfPositionedTextReader
 *
 * @author Maximilian Fäßler <maximilian@faesslerweb.de>
 * @copyright Steffen Kroggel <developer@steffenkroggel.de>, Maximilian Fäßler <maximilian@faesslerweb.de>
 * @package Madj2k\AiAssistantPremium
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License, version 3
 */
final readonly class PdfPageTextExtractor
{
    /**
     * Constructor.
     *
     * @param PdfPageLayoutAnalyzer $layoutAnalyzer Analyzer for geometry-based reading order.
     */
    public function __construct(private PdfPageLayoutAnalyzer $layoutAnalyzer)
    {
    }


    /**
     * Selects the safest extraction strategy for a parsed PDF page.
     *
     * @param \Smalot\PdfParser\Page $pdfPage Parsed PDF page.
     * @param array|null $positionedText Optional preprocessed positioned text entries.
     * @phpstan-param PdfPositionedTextEntryList|null $positionedText
     * @param bool $marginArtifactsExcluded Whether recurring margin artifacts were removed beforehand.
     * @return PdfPageExtractionResult Selected text and layout metadata.
     */
    public function extract(
        Page $pdfPage,
        ?array $positionedText = null,
        bool $marginArtifactsExcluded = false,
    ): PdfPageExtractionResult
    {
        $nativeText = trim($pdfPage->getText());
        $analysis = $this->layoutAnalyzer->analyze($positionedText ?? $pdfPage->getDataTm());

        // Native extraction has no positional provenance, so confirmed margin
        // artifacts cannot safely be removed from it after the fact. Rebuild
        // only affected pages from their already filtered positioned text.
        if ($marginArtifactsExcluded && $analysis->text !== '') {
            return new PdfPageExtractionResult(
                $analysis->text,
                'positioned-filtered',
                $analysis->layoutType,
                $analysis->columnCount,
                $analysis->tableRowCount,
                $analysis->confidence,
            );
        }

        if (in_array($analysis->layoutType, ['columns', 'table', 'mixed'], true) && $analysis->text !== '') {
            return new PdfPageExtractionResult(
                $analysis->text,
                'positioned',
                $analysis->layoutType,
                $analysis->columnCount,
                $analysis->tableRowCount,
                $analysis->confidence,
            );
        }

        if ($analysis->requiresPositionedText && $analysis->text !== '') {
            return new PdfPageExtractionResult(
                $analysis->text,
                'positioned-aligned',
                $analysis->layoutType,
                $analysis->columnCount,
                $analysis->tableRowCount,
                $analysis->confidence,
            );
        }

        if ($nativeText !== '') {
            return new PdfPageExtractionResult(
                $nativeText,
                'native',
                $analysis->layoutType,
                $analysis->columnCount,
                $analysis->tableRowCount,
                $analysis->confidence,
            );
        }

        return new PdfPageExtractionResult(
            $analysis->text,
            'positioned-fallback',
            $analysis->layoutType,
            $analysis->columnCount,
            $analysis->tableRowCount,
            $analysis->confidence,
        );
    }
}
