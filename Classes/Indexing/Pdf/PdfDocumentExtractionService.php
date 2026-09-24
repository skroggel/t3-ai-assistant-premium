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

use Madj2k\AiAssistantPremium\Indexing\Adapter\PdfTextNormalizer;
use Smalot\PdfParser\Config as PdfParserConfig;
use Smalot\PdfParser\Parser;

/**
 * Shared PDF parsing and extraction path for indexing and diagnostics.
 */
final readonly class PdfDocumentExtractionService
{
    public function __construct(
        private PdfTextNormalizer $textNormalizer,
        private PdfPageTextExtractor $pageTextExtractor,
        private PdfMarginArtifactDetector $marginArtifactDetector,
        private PdfPositionedTextAligner $positionedTextAligner = new PdfPositionedTextAligner(),
    ) {
    }

    /** @return array<int, PdfExtractedPage> */
    public function extract(string $path): array
    {
        $parserConfig = new PdfParserConfig();
        $parserConfig->setDataTmFontInfoHasToBeIncluded(true);
        $documentPages = (new Parser([], $parserConfig))->parseFile($path)->getPages();
        $rawPositionedText = array_map(
            fn ($page): array => $this->positionedTextAligner->align(
                $page->getDataTm(),
                $page->getDataCommands(),
            ),
            $documentPages,
        );
        $marginAnalysis = $this->marginArtifactDetector->analyze(array_map(
            static fn ($page, int $index): array => [
                'positionedText' => $rawPositionedText[$index],
                'details' => $page->getDetails(),
            ],
            $documentPages,
            array_keys($documentPages),
        ));

        $pages = [];
        foreach ($documentPages as $pageIndex => $page) {
            $pageMarginAnalysis = $marginAnalysis[$pageIndex] ?? [
                'positionedText' => $rawPositionedText[$pageIndex],
                'artifacts' => [],
            ];
            $result = $this->pageTextExtractor->extract(
                $page,
                $pageMarginAnalysis['positionedText'],
                $pageMarginAnalysis['artifacts'] !== [],
            );
            $pages[] = new PdfExtractedPage(
                $page,
                $rawPositionedText[$pageIndex],
                $pageMarginAnalysis['positionedText'],
                $pageMarginAnalysis['artifacts'],
                $result,
                $this->textNormalizer->normalize($result->text),
            );
        }

        return $pages;
    }
}
