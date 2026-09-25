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

/**
 * Class PdfPageExtractionResult
 *
 * Represents the selected text and extraction metadata for one PDF page.
 *
 * @author Maximilian Fäßler <maximilian@faesslerweb.de>
 * @copyright Steffen Kroggel <developer@steffenkroggel.de>, Maximilian Fäßler <maximilian@faesslerweb.de>
 * @package Madj2k\AiAssistantPremium
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License, version 3
 */
final readonly class PdfPageExtractionResult
{
    /**
     * Constructor.
     *
     * @param string $text Selected page text.
     * @param string $strategy Extraction strategy used for the page.
     * @param string $layoutType Detected layout classification.
     * @param int $columnCount Maximum detected column count.
     * @param int $tableRowCount Number of detected table rows.
     * @param float $confidence Layout-analysis confidence score.
     */
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
