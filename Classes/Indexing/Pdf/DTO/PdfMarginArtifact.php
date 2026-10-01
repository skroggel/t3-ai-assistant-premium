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
 * Class PdfMarginArtifact
 *
 * Represents one recurring header or footer excluded from PDF indexing.
 *
 * @author Maximilian Fäßler <maximilian@faesslerweb.de>
 * @copyright Steffen Kroggel <developer@steffenkroggel.de>, Maximilian Fäßler <maximilian@faesslerweb.de>
 * @package Madj2k\AiAssistantPremium
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License, version 3
 */
final readonly class PdfMarginArtifact
{
    /**
     * Constructor.
     *
     * @param string $type Margin type, either header or footer.
     * @param string $label Human-readable margin label.
     * @param string $text Text excluded from indexing.
     * @param float $xMin Left PDF-space boundary.
     * @param float $xMax Right PDF-space boundary.
     * @param float $yBottom Bottom PDF-space boundary.
     * @param float $yTop Top PDF-space boundary.
     */
    public function __construct(
        public string $type,
        public string $label,
        public string $text,
        public float $xMin,
        public float $xMax,
        public float $yBottom,
        public float $yTop,
    ) {
    }
}
