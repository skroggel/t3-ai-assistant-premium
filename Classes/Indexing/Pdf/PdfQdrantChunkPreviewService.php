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

use Madj2k\AiCore\Indexing\TextChunker;

/**
 * Produces the exact payload.text chunks used by VectorDocumentIndexer.
 */
final readonly class PdfQdrantChunkPreviewService
{
    public function __construct(private TextChunker $textChunker)
    {
    }

    /**
     * @param array<string, mixed> $diagnosticResult
     * @param array{uid: int, title: string, chunkSize: int, chunkOverlap: int, maxChunks: int, minChunkChars: int} $configuration
     * @return array<string, mixed>
     */
    public function addChunkPreview(array $diagnosticResult, array $configuration): array
    {
        $pages = $diagnosticResult['pages'] ?? [];
        foreach ($pages as &$page) {
            $chunks = $this->textChunker->chunkWithOffsets(
                (string)($page['text'] ?? ''),
                $this->positiveOrNull($configuration['chunkSize']),
                $this->positiveOrNull($configuration['chunkOverlap']),
                $this->positiveOrNull($configuration['maxChunks']),
                $this->positiveOrNull($configuration['minChunkChars']),
            );
            $page['chunks'] = array_map(
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
                        (array)($page['lineRegions'] ?? []),
                        static fn (array $region): bool => isset($region['textStart'], $region['textEnd'])
                            && (int)$region['textStart'] < $chunk['end']
                            && (int)$region['textEnd'] > $chunk['start'],
                    ), 'id')),
                ],
                $chunks,
                array_keys($chunks),
            );
            $page['chunkCount'] = count($chunks);
        }
        unset($page);
        $diagnosticResult['pages'] = $pages;

        $diagnosticResult['chunking'] = [
            ...$configuration,
            'chunkSizeLabel' => $this->settingLabel($configuration['chunkSize']),
            'chunkOverlapLabel' => $this->settingLabel($configuration['chunkOverlap']),
            'maxChunksLabel' => $configuration['maxChunks'] > 0
                ? (string)$configuration['maxChunks']
                : 'unlimited',
            'minChunkCharsLabel' => $this->settingLabel($configuration['minChunkChars']),
        ];

        return $diagnosticResult;
    }

    private function positiveOrNull(int $value): ?int
    {
        return $value > 0 ? $value : null;
    }

    private function settingLabel(int $value): string
    {
        return $value > 0 ? (string)$value : 'service default';
    }
}
