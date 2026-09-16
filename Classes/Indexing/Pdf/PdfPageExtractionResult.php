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

/**
 * Contains the selected text and diagnostics for one PDF page.
 */
final readonly class PdfPageExtractionResult
{
    public function __construct(
        public string $text,
        public string $strategy,
        public string $layoutType,
        public int $columnCount,
        public int $tableRowCount,
        public float $confidence,
    ) {
    }
}
