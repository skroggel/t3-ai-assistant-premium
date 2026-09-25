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

namespace Madj2k\AiAssistantPremium\Indexing\Adapter;

use Madj2k\AiAssistant\Config\Config;
use Madj2k\AiCore\DTO\DocumentMetadata;
use Madj2k\AiCore\Exception\IndexingException;
use Madj2k\AiCore\Indexing\Adapter\AdapterInterface;
use Madj2k\AiCore\Indexing\Adapter\MultiDocumentAdapterInterface;
use Madj2k\AiCore\Indexing\DTO\IndexableDocument;
use Madj2k\AiAssistantPremium\License\LicenseService;
use Madj2k\AiAssistantPremium\Indexing\Pdf\DTO\PdfExtractedPage;
use Madj2k\AiAssistantPremium\Indexing\Pdf\DTO\PdfPageExtractionResult;
use Madj2k\AiAssistantPremium\Indexing\Pdf\PdfDocumentExtractionService;

/**
 * Class PdfAdapter
 *
 * Converts PDF files into page-specific documents for the indexing pipeline.
 *
 * @author Steffen Kroggel <developer@steffenkroggel.de>
 * @copyright Steffen Kroggel <developer@steffenkroggel.de>, Maximilian Fäßler <maximilian@faesslerweb.de>
 * @package Madj2k\AiAssistantPremium
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License, version 3
 */
final readonly class PdfAdapter implements AdapterInterface, MultiDocumentAdapterInterface
{
    /**
     * Registry and TypoScript path for the PDF result link behavior.
     *
     * @var string
     */
    public const string LINK_TO_MATCHED_PAGE_CONFIGURATION_KEY = 'indexing.pdf.linkToMatchedPage';

    /**
     * Constructor.
     *
     * @param LicenseService $licenseService Premium license validator.
     * @param PdfDocumentExtractionService $documentExtractionService Shared PDF extraction pipeline.
     */
    public function __construct(
        private LicenseService $licenseService,
        private PdfDocumentExtractionService $documentExtractionService,
    ) {

    }


    /**
     * Returns the registry identifier of the PDF adapter.
     *
     * @return string Adapter identifier.
     */
    public function getIdentifier(): string
    {
        return 'aiassistant.text.pdf';
    }


    /**
     * Returns the file extensions supported by the adapter.
     *
     * @return array<int, string> Supported lowercase file extensions.
     */
    public function getSupportedExtensions(): array
    {
        return ['pdf'];
    }


    /**
     * Determines whether the adapter may process the supplied file path.
     *
     * @param string $path File path to inspect.
     * @return bool Whether the Premium license is valid and the path has a PDF extension.
     * @throws \TYPO3\CMS\Core\Cache\Exception\NoSuchCacheException If the TYPO3 runtime cache is unavailable.
     */
    public function supports(string $path): bool
    {
        return $this->licenseService->isValid()
            && strtolower((string)pathinfo($path, PATHINFO_EXTENSION)) === 'pdf';
    }


    /**
     * Extracts all pages as one normalized text for legacy single-document consumers.
     *
     * @param string $path Absolute path to the PDF file.
     * @param \Madj2k\AiCore\DTO\DocumentMetadata $metadata Mutable source metadata.
     * @return string Normalized text of all PDF pages or an empty string when unavailable.
     * @throws \Madj2k\AiCore\Exception\IndexingException If the PDF cannot be parsed or extracted.
     * @throws \TYPO3\CMS\Core\Cache\Exception\NoSuchCacheException If the TYPO3 runtime cache is unavailable.
     */
    public function extract(string $path, DocumentMetadata $metadata): string
    {
        if (!$this->licenseService->isValid() || !is_file($path)) {
            return '';
        }

        $pdfExtractedPageList = $this->extractPdfPageList($path);
        $metadata->addAdditional('parser', 'smalot/pdfparser');
        $metadata->addAdditional('pdf_page_count', count($pdfExtractedPageList));

        return trim(implode("\n\n", array_map(
            static fn ($pdfExtractedPage): string => $pdfExtractedPage->normalizedText,
            $pdfExtractedPageList
        )));
    }


    /**
     * Extracts one independently indexable document per PDF page.
     *
     * @param string $path Absolute path to the PDF file.
     * @param \Madj2k\AiCore\DTO\DocumentMetadata $metadata Base metadata cloned for every page.
     * @return array<int, \Madj2k\AiCore\Indexing\DTO\IndexableDocument> Page documents in source order.
     * @throws \Madj2k\AiCore\Exception\IndexingException If the PDF cannot be parsed or extracted.
     * @throws \TYPO3\CMS\Core\Cache\Exception\NoSuchCacheException If the TYPO3 runtime cache is unavailable.
     */
    public function extractDocuments(string $path, DocumentMetadata $metadata): array
    {
        if (!$this->licenseService->isValid() || !is_file($path)) {
            return [];
        }

        $pdfExtractedPageList = $this->extractPdfPageList($path);
        $pageCount = count($pdfExtractedPageList);
        $documents = [];

        foreach ($pdfExtractedPageList as $pageIndex => $pdfExtractedPage) {
            $pageNumber = $pageIndex + 1;
            $pageMetadata = $this->createPageMetadata(
                $metadata,
                $pageNumber,
                $pageCount,
                $pdfExtractedPage->result,
            );
            $documents[] = new IndexableDocument(
                $pdfExtractedPage->normalizedText,
                $pageMetadata
            );
        }

        return $documents;
    }


    /**
     * Extracts raw text from the individual PDF pages.
     *
     * @param string $path PDF file path.
     * @return array<int, PdfExtractedPage> Page texts in source order.
     * @throws \Madj2k\AiCore\Exception\IndexingException If parsing or extraction of the PDF fails.
     */
    private function extractPdfPageList(string $path): array
    {
        try {
            return $this->documentExtractionService->extract($path);
        } catch (\Throwable $exception) {
            throw new IndexingException(
                'Could not extract text from PDF file.',
                1780934292,
                $exception
            );
        }
    }


    /**
     * Creates page-specific metadata while retaining the PDF as parent source.
     *
     * @param \Madj2k\AiCore\DTO\DocumentMetadata $metadata Base PDF metadata.
     * @param int $pageNumber One-based page number.
     * @param int $pageCount Total number of PDF pages.
     * @param PdfPageExtractionResult $result Extraction diagnostics.
     * @return \Madj2k\AiCore\DTO\DocumentMetadata Page metadata.
     */
    private function createPageMetadata(
        DocumentMetadata $metadata,
        int $pageNumber,
        int $pageCount,
        PdfPageExtractionResult $result,
    ): DocumentMetadata {
        /** @var \Madj2k\AiCore\DTO\DocumentMetadata $pageMetadata */
        $pageMetadata = clone $metadata;
        $sourceIdentifier = $metadata->getSourceIdentifier();

        $pageMetadata->setSourceIdentifier($sourceIdentifier . '#page-' . $pageNumber);
        $pageMetadata->addAdditional('parser', 'smalot/pdfparser');
        $pageMetadata->addAdditional('pdf_source_identifier', $sourceIdentifier);
        $pageMetadata->addAdditional('pdf_page_number', $pageNumber);
        $pageMetadata->addAdditional('pdf_page_count', $pageCount);
        $pageMetadata->addAdditional('pdf_extraction_strategy', $result->strategy);
        $pageMetadata->addAdditional('pdf_layout_type', $result->layoutType);
        $pageMetadata->addAdditional('pdf_column_count', $result->columnCount);
        $pageMetadata->addAdditional('pdf_table_row_count', $result->tableRowCount);
        $pageMetadata->addAdditional('pdf_extraction_confidence', $result->confidence);

        if ($metadata->getUrl() !== '') {
            $sourceUrl = $metadata->getUrl();
            $pageUrl = $this->createPageUrl($sourceUrl, $pageNumber);

            $pageMetadata->addAdditional('pdf_source_url', $sourceUrl);
            $pageMetadata->addAdditional('pdf_page_url', $pageUrl);

            if ($this->shouldLinkToMatchedPage()) {
                $pageMetadata->setUrl($pageUrl);
            }
        }

        return $pageMetadata;
    }


    /**
     * Returns whether result URLs should point directly to the matched PDF page.
     *
     * @return bool True when page-specific links are enabled.
     */
    private function shouldLinkToMatchedPage(): bool
    {
        try {
            $configuredValue = Config::get(self::LINK_TO_MATCHED_PAGE_CONFIGURATION_KEY, false);
        } catch (\Madj2k\AiCore\Exception\AppException) {
            return false;
        }

        return in_array(
            $configuredValue,
            [true, 1, '1'],
            true
        );
    }


    /**
     * Adds a PDF viewer page fragment without changing the canonical source URL.
     *
     * @param string $url Canonical PDF URL.
     * @param int $pageNumber One-based page number.
     * @return string Page-specific PDF URL.
     */
    private function createPageUrl(string $url, int $pageNumber): string
    {
        $fragmentPosition = strpos($url, '#');
        if ($fragmentPosition !== false) {
            $url = substr($url, 0, $fragmentPosition);
        }

        return $url . '#page=' . $pageNumber;
    }
}
