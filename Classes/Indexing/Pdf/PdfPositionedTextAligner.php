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
 * Aligns Smalot's calculated text matrices with the native PDF show-text
 * operators. Some PDFs contain empty runs that make getDataTm() associate the
 * following decoded strings with the preceding matrices. The command stream
 * retains the correct one-to-one order in those documents.
 */
final readonly class PdfPositionedTextAligner
{
    /**
     * @param array<int, array<int, mixed>> $positionedText
     * @param array<int, array<string, mixed>> $commands
     * @return array<int, array<int, mixed>>
     */
    public function align(array $positionedText, array $commands): array
    {
        $commandTexts = [];
        foreach ($commands as $command) {
            $operator = (string)($command['o'] ?? '');
            if (!in_array($operator, ['Tj', 'TJ', "'", '"'], true)) {
                continue;
            }
            $commandTexts[] = $this->extractShownText($command['c'] ?? '');
        }

        // Never guess when Form XObjects or unsupported operators make both
        // representations differ structurally.
        if ($commandTexts === [] || count($commandTexts) !== count($positionedText)) {
            return $positionedText;
        }

        foreach ($positionedText as $index => &$entry) {
            if (!isset($entry[0]) || !is_array($entry[0])) {
                return $positionedText;
            }
            // Text commands may still contain bytes in a font-specific
            // encoding. getDataTm() has already decoded those through the PDF
            // font map, so retain that value rather than introducing Unicode
            // replacement characters into extraction and diagnostics.
            if ($this->isUsableCommandText(
                $commandTexts[$index],
                (string)($entry[1] ?? ''),
            )) {
                $entry[1] = $commandTexts[$index];
            }
        }
        unset($entry);

        return $positionedText;
    }

    private function isUsableCommandText(string $commandText, string $decodedText): bool
    {
        if (!mb_check_encoding($commandText, 'UTF-8')) {
            return false;
        }
        // A syntactically valid UTF-8 string can still be mojibake produced by
        // interpreting already UTF-8 encoded PDF bytes as Windows-1252. The
        // font-decoded getDataTm() value is authoritative in that case.
        if (preg_match('/(?:Ã.|Â.|â[\x{0080}-\x{00BF}])/u', $commandText) === 1
            && preg_match('/(?:Ã.|Â.|â[\x{0080}-\x{00BF}])/u', $decodedText) !== 1
        ) {
            return false;
        }
        // Some hex-string operands reach the command representation as their
        // hexadecimal source while getDataTm() already contains decoded text.
        $compact = preg_replace('/\s+/u', '', $commandText) ?? $commandText;
        if (strlen($compact) >= 16
            && strlen($compact) % 2 === 0
            && ctype_xdigit($compact)
            && !ctype_xdigit(preg_replace('/\s+/u', '', $decodedText) ?? $decodedText)
        ) {
            return false;
        }
        if (strlen($compact) >= 4
            && strlen($compact) % 4 === 0
            && ctype_xdigit($compact)
            && $commandText !== $decodedText
            && preg_match('/[^\x00-\x7F]/', $decodedText) === 1
        ) {
            return false;
        }

        return true;
    }

    private function extractShownText(mixed $operand): string
    {
        if (is_string($operand)) {
            return $operand;
        }
        if (!is_array($operand)) {
            return '';
        }

        if (isset($operand['t'], $operand['c'])
            && in_array((string)$operand['t'], ['(', '<'], true)
        ) {
            return is_string($operand['c']) ? $operand['c'] : '';
        }
        if (isset($operand['t'])) {
            return '';
        }

        $text = '';
        foreach ($operand as $item) {
            $text .= $this->extractShownText($item);
        }
        return $text;
    }
}
