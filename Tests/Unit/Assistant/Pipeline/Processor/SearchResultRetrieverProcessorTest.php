<?php
declare(strict_types=1);

namespace Madj2k\AiAssistantPremium\Tests\Unit\Assistant\Pipeline\Processor;

use Madj2k\AiAssistant\Assistant\Domain\Model\AssistantPipelineStep;
use Madj2k\AiAssistantPremium\Assistant\Pipeline\Processor\SearchResultRetrieverProcessor;
use Madj2k\AiCore\Assistant\Context\Answer\AnswerState;
use Madj2k\AiCore\Assistant\Context\Assistant\AssistantContext;
use Madj2k\AiCore\Assistant\Context\Context;
use Madj2k\AiCore\Assistant\Context\Request\History;
use Madj2k\AiCore\Assistant\Context\Request\Request;
use Madj2k\AiCore\Assistant\Context\Retrieval\RetrievalGroup;
use Madj2k\AiCore\Assistant\Context\Retrieval\RetrievalResult;
use Madj2k\AiCore\Assistant\Context\Trace\ProcessingTrace;
use Madj2k\AiCore\Assistant\Log\PipelineLoggerInterface;
use PHPUnit\Framework\TestCase;

final class SearchResultRetrieverProcessorTest extends TestCase
{
    public function testAddsCapturedSearchResultsAsNamedRetrieval(): void
    {
        $retrieval = new RetrievalResult();
        $retrieval->storeGroup(new RetrievalGroup('qdrant', 'test', 'query'));
        $context = new Context(
            new AssistantContext(),
            new Request('query', 'chat', runtimeSettings: ['search' => ['capturedResults' => [
                'effectiveQuery' => 'TYPO3',
                'total' => 1,
                'results' => [[
                    'id' => 'search:1',
                    'score' => 1.0,
                    'title' => 'Result',
                    'text' => 'Visible search result content',
                ]],
            ]]]),
            new History([]),
            $retrieval,
            new AnswerState(),
            new ProcessingTrace(),
        );
        $step = new AssistantPipelineStep();
        $step->setTitle('Search results');

        (new SearchResultRetrieverProcessor($this->createStub(PipelineLoggerInterface::class)))
            ->process($context, $step);

        self::assertSame(['qdrant', 'Search results'], array_map(
            static fn (RetrievalGroup $group): string => $group->identifier,
            $context->getRetrieval()->getGroups(),
        ));
        self::assertSame('Visible search result content', $context->getRetrieval()->getDocuments()[0]->text);
    }
}
