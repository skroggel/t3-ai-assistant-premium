<?php
declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
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
use Smalot\PdfParser\Parser;

/**
 * Class PdfContentAdapter
 *
 * Extracts text from PDF files page by page.
 *
 * @author Steffen Kroggel <developer@steffenkroggel.de>
 * @copyright Steffen Kroggel <developer@steffenkroggel.de>
 * @package Madj2k\AiAssistantPremium
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License, version 3 or later
 */
final class PdfAdapter implements AdapterInterface, MultiDocumentAdapterInterface
{
    /**
     * Registry and TypoScript path for the PDF result link behavior.
     *
     * @var string
     */
    public const LINK_TO_MATCHED_PAGE_CONFIGURATION_KEY = 'indexing.pdf.linkToMatchedPage';


    /**
     * Constructor.
     *
     * @param \Madj2k\AiAssistantPremium\Indexing\Adapter\PdfTextNormalizer $textNormalizer Text normalizer.
     */
    public function __construct(
        private readonly PdfTextNormalizer $textNormalizer,
        private readonly LicenseService $licenseService
    ) {

    }


    /**
     * @inheritDoc
     */
    public function getIdentifier(): string
    {
        return 'aiassistant.text.pdf';
    }


    /**
     * @inheritDoc
     */
    public function getSupportedExtensions(): array
    {
        return ['pdf'];
    }


    /**
     * @inheritDoc
     */
    public function supports(string $path): bool
    {
        return $this->licenseService->isValid()
            && strtolower((string)pathinfo($path, PATHINFO_EXTENSION)) === 'pdf';
    }


    /**
     * @inheritDoc
     */
    public function extract(string $path, DocumentMetadata $metadata): ?IndexableDocument
    {
        if (!$this->licenseService->isValid() || !is_file($path)) {
            return null;
        }

        $pages = $this->extractPages($path);
        $metadata->addAdditional('parser', 'smalot/pdfparser');
        $metadata->addAdditional('pdf_page_count', count($pages));

        return new IndexableDocument(trim(implode("\n\n", array_map(
            fn (string $page): string => $this->textNormalizer->normalize($page),
            $pages
        ))), $metadata);
    }


    /**
     * @inheritDoc
     */
    public function extractDocuments(string $path, DocumentMetadata $metadata): array
    {
        if (!$this->licenseService->isValid() || !is_file($path)) {
            return [];
        }

        $pages = $this->extractPages($path);
        $pageCount = count($pages);
        $documents = [];

        foreach ($pages as $pageIndex => $page) {
            $pageNumber = $pageIndex + 1;
            $pageMetadata = $this->createPageMetadata($metadata, $pageNumber, $pageCount);
            $documents[] = new IndexableDocument(
                $this->textNormalizer->normalize($page),
                $pageMetadata
            );
        }

        return $documents;
    }


    /**
     * Extracts raw text from the individual PDF pages.
     *
     * @param string $path PDF file path.
     * @return array<int, string> Raw page texts in source order.
     * @throws \Madj2k\AiCore\Exception\IndexingException
     */
    private function extractPages(string $path): array
    {
        try {
            $document = (new Parser())->parseFile($path);
            $pages = [];

            foreach ($document->getPages() as $page) {
                $pages[] = $page->getText();
            }

            return $pages;
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
     * @return \Madj2k\AiCore\DTO\DocumentMetadata Page metadata.
     */
    private function createPageMetadata(
        DocumentMetadata $metadata,
        int $pageNumber,
        int $pageCount
    ): DocumentMetadata {
        /** @var \Madj2k\AiCore\DTO\DocumentMetadata $pageMetadata */
        $pageMetadata = clone $metadata;
        $sourceIdentifier = $metadata->getSourceIdentifier();

        $pageMetadata->setSourceIdentifier($sourceIdentifier . '#page-' . $pageNumber);
        $pageMetadata->addAdditional('parser', 'smalot/pdfparser');
        $pageMetadata->addAdditional('pdf_source_identifier', $sourceIdentifier);
        $pageMetadata->addAdditional('pdf_page_number', $pageNumber);
        $pageMetadata->addAdditional('pdf_page_count', $pageCount);

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
        return in_array(
            Config::get(self::LINK_TO_MATCHED_PAGE_CONFIGURATION_KEY, false),
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
