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

/**
 * Normalizes text extracted from individual PDF pages.
 *
 * @author Steffen Kroggel <developer@steffenkroggel.de>
 * @copyright Steffen Kroggel <developer@steffenkroggel.de>
 * @package Madj2k\AiAssistantPremium
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License, version 3 or later
 */
final class PdfTextNormalizer
{
    /**
     * Normalizes PDF text without flattening tables or lists.
     *
     * @param string $text Raw page text.
     * @return string Normalized page text.
     */
    public function normalize(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = str_replace(["\u{00A0}", "\u{202F}"], ' ', $text);
        $text = str_replace("\u{00AD}", '', $text);
        $text = strtr($text, [
            "\u{FB00}" => 'ff',
            "\u{FB01}" => 'fi',
            "\u{FB02}" => 'fl',
            "\u{FB03}" => 'ffi',
            "\u{FB04}" => 'ffl',
        ]);
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? $text;

        $blocks = preg_split('/\n[ \t]*\n+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY);
        if ($blocks === false) {
            return trim($text);
        }

        $normalizedBlocks = [];
        foreach ($blocks as $block) {
            $normalizedBlock = $this->normalizeBlock($block);
            if ($normalizedBlock !== '') {
                $normalizedBlocks[] = $normalizedBlock;
            }
        }

        return implode("\n\n", $normalizedBlocks);
    }


    /**
     * Normalizes one paragraph or structured text block.
     *
     * @param string $block Text block.
     * @return string Normalized block.
     */
    private function normalizeBlock(string $block): string
    {
        $lines = array_values(array_filter(
            array_map(
                static fn (string $line): string => rtrim($line),
                explode("\n", $block)
            ),
            static fn (string $line): bool => trim($line) !== ''
        ));

        if ($lines === []) {
            return '';
        }

        if ($this->isStructuredBlock($lines)) {
            return implode("\n", $lines);
        }

        $text = implode("\n", array_map('trim', $lines));
        $text = preg_replace('/(?<=\p{L})-[ \t]*\n[ \t]*(?=\p{Ll})/u', '', $text) ?? $text;
        $text = str_replace("\n", ' ', $text);
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;

        return trim($text);
    }


    /**
     * Detects blocks whose line structure carries meaning.
     *
     * @param array<int, string> $lines Non-empty block lines.
     * @return bool True for tables and lists.
     */
    private function isStructuredBlock(array $lines): bool
    {
        $columnLines = 0;
        $listLines = 0;
        $delimitedLines = 0;

        foreach ($lines as $line) {
            if (preg_match('/\S[ \t]{2,}\S/u', $line) === 1) {
                $columnLines++;
            }

            if (preg_match('/^\s*(?:[-*]|\d+[.)])\s+\S/u', $line) === 1) {
                $listLines++;
            }

            if (substr_count($line, ' | ') >= 1) {
                $delimitedLines++;
            }
        }

        return $columnLines >= 2 || $listLines >= 2 || $delimitedLines >= 2;
    }
}
