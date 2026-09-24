<?php
declare(strict_types=1);

namespace Madj2k\AiAssistantPremium\Tests\Unit\Indexing\Pdf;

use Madj2k\AiAssistantPremium\Indexing\Pdf\PdfQdrantChunkPreviewService;
use Madj2k\AiCore\Indexing\TextChunker;
use PHPUnit\Framework\TestCase;

final class PdfQdrantChunkPreviewServiceTest extends TestCase
{
    public function testUsesTheProductionChunkerSettingsAndPayloadText(): void
    {
        $subject = new PdfQdrantChunkPreviewService(new TextChunker());

        $result = $subject->addChunkPreview([
            'filename' => 'example.pdf',
            'pageCount' => 1,
            'pages' => [[
                'number' => 1,
                'text' => "Alpha   beta\ngamma delta epsilon",
                'lineRegions' => [
                    ['id' => 'line-1', 'textStart' => 0, 'textEnd' => 10],
                    ['id' => 'line-2', 'textStart' => 11, 'textEnd' => 30],
                ],
            ]],
        ], [
            'uid' => 42,
            'title' => 'PDF indexer',
            'chunkSize' => 12,
            'chunkOverlap' => 3,
            'maxChunks' => 2,
            'minChunkChars' => 1,
        ]);

        self::assertSame(2, $result['pages'][0]['chunkCount']);
        self::assertSame('Alpha beta g', $result['pages'][0]['chunks'][0]['text']);
        self::assertSame('a gamma delt', $result['pages'][0]['chunks'][1]['text']);
        self::assertSame(12, $result['pages'][0]['chunks'][0]['characterCount']);
        self::assertSame(0, $result['pages'][0]['chunks'][0]['start']);
        self::assertSame(12, $result['pages'][0]['chunks'][0]['end']);
        self::assertSame('line-1,line-2', $result['pages'][0]['chunks'][0]['regionIds']);
        self::assertSame(9, $result['pages'][0]['chunks'][1]['start']);
        self::assertSame(21, $result['pages'][0]['chunks'][1]['end']);
        self::assertSame('PDF indexer', $result['chunking']['title']);
        self::assertSame('12', $result['chunking']['chunkSizeLabel']);
        self::assertSame('2', $result['chunking']['maxChunksLabel']);
    }

    public function testLabelsZeroValuesAsProductionServiceDefaults(): void
    {
        $subject = new PdfQdrantChunkPreviewService(new TextChunker());

        $result = $subject->addChunkPreview([
            'pages' => [['text' => 'Preview']],
        ], [
            'uid' => 0,
            'title' => 'Service defaults',
            'chunkSize' => 0,
            'chunkOverlap' => 0,
            'maxChunks' => 0,
            'minChunkChars' => 0,
        ]);

        self::assertSame('service default', $result['chunking']['chunkSizeLabel']);
        self::assertSame('service default', $result['chunking']['chunkOverlapLabel']);
        self::assertSame('unlimited', $result['chunking']['maxChunksLabel']);
        self::assertSame('service default', $result['chunking']['minChunkCharsLabel']);
    }
}
