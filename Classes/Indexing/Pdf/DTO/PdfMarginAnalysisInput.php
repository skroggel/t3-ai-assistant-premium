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
 * Class PdfMarginAnalysisInput
 *
 * Represents the positioned text and page metadata required for margin analysis.
 *
 * @phpstan-import-type PdfPositionedTextEntryList from PdfPositionedTextReader
 *
 * @author Maximilian Fäßler <maximilian@faesslerweb.de>
 * @copyright Steffen Kroggel <developer@steffenkroggel.de>, Maximilian Fäßler <maximilian@faesslerweb.de>
 * @package Madj2k\AiAssistantPremium
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License, version 3
 */
final readonly class PdfMarginAnalysisInput
{
    /**
     * Constructor.
     *
     * @param array $positionedText Positioned text entries supplied by Smalot.
     * @phpstan-param PdfPositionedTextEntryList $positionedText
     * @param array<string, mixed> $details Smalot page details containing dimensions and metadata.
     */
    public function __construct(
        public array $positionedText,
        public array $details,
    ) {
    }
}
