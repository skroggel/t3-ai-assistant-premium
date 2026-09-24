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
 * One page emitted by the shared production PDF extraction path.
 */
final readonly class PdfExtractedPage
{
    /**
     * @param array<int, array<int, mixed>> $rawPositionedText
     * @param array<int, array<int, mixed>> $positionedText
     * @param array<int, array<string, mixed>> $marginArtifacts
     */
    public function __construct(
        public Page $page,
        public array $rawPositionedText,
        public array $positionedText,
        public array $marginArtifacts,
        public PdfPageExtractionResult $result,
        public string $normalizedText,
    ) {
    }
}
