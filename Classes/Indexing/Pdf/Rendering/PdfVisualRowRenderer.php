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

namespace Madj2k\AiAssistantPremium\Indexing\Pdf\Rendering;

use Madj2k\AiAssistantPremium\Indexing\Pdf\Geometry\PdfPositionedTextReader;

/**
 * Class PdfVisualRowRenderer
 *
 * Serializes visual PDF rows and sequential text blocks into normalized text.
 *
 * @phpstan-import-type PdfVisualRowList from PdfPositionedTextReader
 *
 * @author Maximilian Fäßler <maximilian@faesslerweb.de>
 * @copyright Steffen Kroggel <developer@steffenkroggel.de>, Maximilian Fäßler <maximilian@faesslerweb.de>
 * @package Madj2k\AiAssistantPremium
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License, version 3
 */
final readonly class PdfVisualRowRenderer
{
    /**
     * Serializes visual rows while retaining cell separators between independent parts.
     *
     * @param array $visualRowList Visual rows.
     * @phpstan-param PdfVisualRowList $visualRowList
     * @return string Row-wise text joined by line breaks.
     */
    public function renderRows(array $visualRowList): string
    {
        return implode("\n", array_map(
            static fn (array $visualRow): string => implode(' | ', array_column($visualRow['parts'], 'text')),
            $visualRowList,
        ));
    }


    /**
     * Serializes ordinary prose without separators between text parts on the same line.
     *
     * @param array $visualRowList Visual rows.
     * @phpstan-param PdfVisualRowList $visualRowList
     * @return string Plain text in visual row order.
     */
    public function renderPlainRows(array $visualRowList): string
    {
        return implode("\n", array_map(
            static fn (array $visualRow): string => implode(' ', array_column($visualRow['parts'], 'text')),
            $visualRowList,
        ));
    }


    /**
     * Joins sequential text blocks while repairing explicit cross-column hyphenation.
     *
     * @param array<int, string> $textBlockList Sequential non-normalized text blocks.
     * @return string Joined text with paragraph boundaries and repaired column hyphenation.
     */
    public function joinSequentialBlocks(array $textBlockList): string
    {
        $result = '';
        foreach ($textBlockList as $textBlock) {
            $textBlock = trim($textBlock);
            if ($textBlock === '') {
                continue;
            }
            if ($result !== ''
                && preg_match('/\p{L}-$/u', $result) === 1
                && preg_match('/^\p{Ll}/u', $textBlock) === 1
            ) {
                $result = (preg_replace('/-$/u', '', $result) ?? $result) . $textBlock;
                continue;
            }
            $result .= ($result === '' ? '' : "\n\n") . $textBlock;
        }
        return $result;
    }
}
