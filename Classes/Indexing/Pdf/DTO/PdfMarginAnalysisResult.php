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

/**
 * Class PdfMarginAnalysisResult
 *
 * Represents the filtered text and excluded margin artifacts of one PDF page.
 *
 * @phpstan-import-type PdfPositionedTextEntryList from PdfPositionedTextReader
 *
 * @author Maximilian Fäßler <maximilian@faesslerweb.de>
 * @copyright Steffen Kroggel <developer@steffenkroggel.de>, Maximilian Fäßler <maximilian@faesslerweb.de>
 * @package Madj2k\AiAssistantPremium
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License, version 3
 */
final readonly class PdfMarginAnalysisResult
{
    /**
     * Constructor.
     *
     * @param array $positionedText Positioned entries after margin filtering.
     * @phpstan-param PdfPositionedTextEntryList $positionedText
     * @param array<int, PdfMarginArtifact> $artifacts Confirmed header and footer artifacts.
     */
    public function __construct(
        public array $positionedText,
        public array $artifacts,
    ) {
    }
}
