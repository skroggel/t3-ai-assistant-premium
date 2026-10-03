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

use Madj2k\AiAssistantPremium\Indexing\Adapter\PdfTextNormalizer;
use Madj2k\AiAssistantPremium\Indexing\Pdf\DTO\PdfExtractedPage;
use Madj2k\AiAssistantPremium\Indexing\Pdf\DTO\PdfMarginAnalysisInput;
use Madj2k\AiAssistantPremium\Indexing\Pdf\DTO\PdfMarginAnalysisResult;
use Madj2k\AiAssistantPremium\Indexing\Pdf\Preprocessing\PdfMarginArtifactDetector;
use Madj2k\AiAssistantPremium\Indexing\Pdf\Preprocessing\PdfPositionedTextAligner;
use Smalot\PdfParser\Config as PdfParserConfig;
use Smalot\PdfParser\Parser;

/**
 * Class PdfDocumentExtractionService
 *
 * Coordinates the shared PDF parsing and extraction path for indexing and diagnostics.
 *
 * @author Maximilian Fäßler <maximilian@faesslerweb.de>
 * @copyright Steffen Kroggel <developer@steffenkroggel.de>, Maximilian Fäßler <maximilian@faesslerweb.de>
 * @package Madj2k\AiAssistantPremium
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License, version 3
 */
final readonly class PdfDocumentExtractionService
{
    /**
     * Constructor.
     *
     * @param PdfTextNormalizer $textNormalizer Normalizer used by the indexing adapter.
     * @param PdfPageTextExtractor $pageTextExtractor Strategy selector for individual PDF pages.
     * @param PdfMarginArtifactDetector $marginArtifactDetector Detector for recurring headers and footers.
     * @param PdfPositionedTextAligner $positionedTextAligner Aligner for parser text objects and content commands.
     */
    public function __construct(
        private PdfTextNormalizer $textNormalizer,
        private PdfPageTextExtractor $pageTextExtractor,
        private PdfMarginArtifactDetector $marginArtifactDetector,
        private PdfPositionedTextAligner $positionedTextAligner = new PdfPositionedTextAligner(),
    ) {
    }


    /**
     * Parses a PDF and extracts normalized page results for indexing and diagnostics.
     *
     * @param string $path Absolute path to the readable PDF file.
     * @return array<int, PdfExtractedPage> Extracted pages in document order.
     * @throws \Smalot\PdfParser\Exception\MissingCatalogException If the parsed document has no page catalog.
     * @throws \Exception If the PDF file cannot be parsed.
     */
    public function extract(string $path): array
    {
        $parserConfig = new PdfParserConfig();
        $parserConfig->setDataTmFontInfoHasToBeIncluded(true);
        $pdfPageList = new Parser([], $parserConfig)->parseFile($path)->getPages();

        // Reconcile decoded glyphs with content-stream commands before any
        // cross-page filtering so indexing and diagnostics share one source.
        $rawPositionedText = array_map(
            fn ($pdfPage): array => $this->positionedTextAligner->align(
                $pdfPage->getDataTm(),
                $pdfPage->getDataCommands(),
            ),
            $pdfPageList,
        );

        // Headers and footers are removed only after repetition across the
        // complete document has provided enough evidence.
        $marginAnalysis = $this->marginArtifactDetector->analyze(array_map(
            static fn ($pdfPage, int $index): PdfMarginAnalysisInput => new PdfMarginAnalysisInput(
                $rawPositionedText[$index],
                $pdfPage->getDetails(),
            ),
            $pdfPageList,
            array_keys($pdfPageList),
        ));

        $pdfExtractedPageList = [];
        foreach ($pdfPageList as $pageIndex => $pdfPage) {
            $pageMarginAnalysis = $marginAnalysis[$pageIndex]
                ?? new PdfMarginAnalysisResult($rawPositionedText[$pageIndex], []);
            $result = $this->pageTextExtractor->extract(
                $pdfPage,
                $pageMarginAnalysis->positionedText,
                $pageMarginAnalysis->artifacts !== [],
            );
            $pdfExtractedPageList[] = new PdfExtractedPage(
                $pdfPage,
                $rawPositionedText[$pageIndex],
                $pageMarginAnalysis->positionedText,
                $pageMarginAnalysis->artifacts,
                $result,
                $this->textNormalizer->normalize($result->text),
            );
        }

        return $pdfExtractedPageList;
    }
}
