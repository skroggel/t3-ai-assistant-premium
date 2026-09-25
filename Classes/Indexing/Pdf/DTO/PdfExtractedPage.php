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

namespace Madj2k\AiAssistantPremium\Indexing\Pdf\DTO;

use Madj2k\AiAssistantPremium\Indexing\Pdf\Geometry\PdfPositionedTextReader;
use Smalot\PdfParser\Page;

/**
 * Class PdfExtractedPage
 *
 * Represents one page emitted by the shared production PDF extraction path.
 *
 * @phpstan-import-type PdfPositionedTextEntryList from PdfPositionedTextReader
 *
 * @author Maximilian Fäßler <maximilian@faesslerweb.de>
 * @copyright Steffen Kroggel <developer@steffenkroggel.de>, Maximilian Fäßler <maximilian@faesslerweb.de>
 * @package Madj2k\AiAssistantPremium
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License, version 3
 */
final readonly class PdfExtractedPage
{
    /**
     * Constructor.
     *
     * @param \Smalot\PdfParser\Page $pdfPage Parsed source page.
     * @param array $rawPositionedText Unfiltered positioned text entries.
     * @phpstan-param PdfPositionedTextEntryList $rawPositionedText
     * @param array $positionedText Positioned entries after margin filtering.
     * @phpstan-param PdfPositionedTextEntryList $positionedText
     * @param array<int, PdfMarginArtifact> $marginArtifacts Detected header and footer artifacts.
     * @param PdfPageExtractionResult $result Selected extraction result.
     * @param string $normalizedText Normalized text passed to the indexer.
     */
    public function __construct(
        public Page $pdfPage,
        public array $rawPositionedText,
        public array $positionedText,
        public array $marginArtifacts,
        public PdfPageExtractionResult $result,
        public string $normalizedText,
    ) {
    }
}
