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

use Smalot\PdfParser\Page;

/**
 * Selects native or geometry-based text extraction for one PDF page.
 */
final readonly class PdfPageTextExtractor
{
    public function __construct(private PdfPageLayoutAnalyzer $layoutAnalyzer)
    {
    }

    /**
     * @param array<int, array<int, mixed>>|null $positionedText
     */
    public function extract(
        Page $page,
        ?array $positionedText = null,
        bool $marginArtifactsExcluded = false,
    ): PdfPageExtractionResult
    {
        $nativeText = trim($page->getText());
        $analysis = $this->layoutAnalyzer->analyze($positionedText ?? $page->getDataTm());

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
