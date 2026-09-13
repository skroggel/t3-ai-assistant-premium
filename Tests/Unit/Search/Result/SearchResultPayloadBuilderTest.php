<?php
declare(strict_types=1);

namespace Madj2k\AiAssistantPremium\Tests\Unit\Search\Result;

use GuzzleHttp\Psr7\ServerRequest;
use Madj2k\AiAssistantPremium\Search\Result\SearchResultPayloadBuilder;
use PHPUnit\Framework\TestCase;

final class SearchResultPayloadBuilderTest extends TestCase
{
    public function testMapsVisibleRowsToTheCommonContract(): void
    {
        $request = (new ServerRequest('GET', '/search'))->withQueryParams([
            'tx_aiassistantpremium_search' => [
                'processed' => 1,
                'integration' => 'ke_search',
                'chatIdentifier' => 'chat-1',
                'originalQuery' => 'Was ist Döhler?',
                'effectiveQuery' => 'Döhler',
            ],
        ]);
        $subject = new SearchResultPayloadBuilder();
        $payload = $subject->build(
            rows: [[
                'orig_uid' => 42,
                'orig_pid' => 7,
                'type' => 'page',
                'title_text' => '<b>About Döhler</b>',
                'content_text' => 'Natural &amp; sustainable',
                'url' => '/about',
                'date_timestamp' => 1234,
            ]],
            fields: [
                'id' => 'orig_uid',
                'pageId' => 'orig_pid',
                'type' => 'type',
                'title' => 'title_text',
                'text' => 'content_text',
                'url' => 'url',
                'changedAt' => 'date_timestamp',
            ],
            total: 10,
            page: 1,
            request: $request,
        );

        self::assertSame('Was ist Döhler?', $payload['originalQuery']);
        self::assertSame('Döhler', $payload['effectiveQuery']);
        self::assertSame('ke_search:page:42', $payload['results'][0]['id']);
        self::assertSame('About Döhler', $payload['results'][0]['title']);
        self::assertSame('Natural & sustainable', $payload['results'][0]['text']);
        self::assertSame(1.0, $payload['results'][0]['score']);
    }

    public function testRendersScriptSafeJson(): void
    {
        $subject = new SearchResultPayloadBuilder();
        $element = $subject->renderElement([
            'chatIdentifier' => 'chat-1',
            'results' => [['text' => '</script><script>alert(1)</script>']],
        ]);

        self::assertStringContainsString('class="js-aiassistant-search-results"', $element);
        self::assertStringNotContainsString('</script><script>', $element);
        self::assertStringContainsString('\\u003C/script\\u003E', $element);
    }
}
