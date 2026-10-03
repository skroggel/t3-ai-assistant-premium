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

namespace Madj2k\AiAssistantPremium\Backend\PdfDiagnostics;

use Madj2k\AiCore\Indexing\TextChunker;

/**
 * Class PdfQdrantChunkPreviewService
 *
 * Produces a diagnostic preview of the exact payload text chunks used for indexing.
 *
 * @author Maximilian Fäßler <maximilian@faesslerweb.de>
 * @copyright Steffen Kroggel <developer@steffenkroggel.de>, Maximilian Fäßler <maximilian@faesslerweb.de>
 * @package Madj2k\AiAssistantPremium
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License, version 3
 */
final readonly class PdfQdrantChunkPreviewService
{
    /**
     * Constructor.
     *
     * @param TextChunker $textChunker Production text chunker used before Qdrant persistence.
     */
    public function __construct(private TextChunker $textChunker)
    {
    }


    /**
     * Adds production-equivalent chunk texts, offsets and matching PDF regions to a diagnostic result.
     *
     * @param array<string, mixed> $diagnosticResult PDF diagnostic result containing normalized page text.
     * @param array{uid: int, title: string, chunkSize: int, chunkOverlap: int, maxChunks: int, minChunkChars: int} $configuration Effective file-indexer chunk settings.
     * @return array<string, mixed> Diagnostic result enriched with chunks and effective configuration values.
     */
    public function addChunkPreview(array $diagnosticResult, array $configuration): array
    {
        $pdfDiagnosticPageList = $diagnosticResult['pages'] ?? [];
        foreach ($pdfDiagnosticPageList as &$pdfDiagnosticPage) {
            $chunks = $this->textChunker->chunkWithOffsets(
                (string)($pdfDiagnosticPage['text'] ?? ''),
                $this->positiveOrNull($configuration['chunkSize']),
                $this->positiveOrNull($configuration['chunkOverlap']),
                $this->positiveOrNull($configuration['maxChunks']),
                $this->positiveOrNull($configuration['minChunkChars']),
            );

            // Half-open range intersection maps every production chunk back
            // to all visual PDF lines touched by that chunk, including overlap.
            $pdfDiagnosticPage['chunks'] = array_map(
                static fn (array $chunk, int $index): array => [
                    'number' => $index + 1,
                    'index' => $index,
                    'text' => $chunk['text'],
                    'characterCount' => mb_strlen($chunk['text']),
                    'start' => $chunk['start'],
                    'end' => $chunk['end'],
                    'startDisplay' => $chunk['start'] + 1,
                    'endDisplay' => $chunk['end'],
                    'regionIds' => implode(',', array_column(array_filter(
                        (array)($pdfDiagnosticPage['lineRegions'] ?? []),
                        static fn (array $region): bool => isset($region['textStart'], $region['textEnd'])
                            && (int)$region['textStart'] < $chunk['end']
                            && (int)$region['textEnd'] > $chunk['start'],
                    ), 'id')),
                ],
                $chunks,
                array_keys($chunks),
            );
            $pdfDiagnosticPage['chunkCount'] = count($chunks);
        }
        unset($pdfDiagnosticPage);
        $diagnosticResult['pages'] = $pdfDiagnosticPageList;

        $diagnosticResult['chunking'] = [
            ...$configuration,
        ];

        return $diagnosticResult;
    }


    /**
     * Converts non-positive persisted settings into the chunker's default marker.
     *
     * @param int $value Persisted numeric setting.
     * @return int|null Positive value or null to use the service default.
     */
    private function positiveOrNull(int $value): ?int
    {
        return $value > 0 ? $value : null;
    }
}
