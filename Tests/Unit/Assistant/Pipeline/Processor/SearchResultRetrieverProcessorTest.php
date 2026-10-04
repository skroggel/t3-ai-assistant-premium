<?php
declare(strict_types=1);

namespace Madj2k\AiAssistantPremium\Tests\Unit\Assistant\Pipeline\Processor;

use Madj2k\AiAssistant\Assistant\Domain\Model\AssistantPipelineStep;
use Madj2k\AiAssistantPremium\Assistant\Pipeline\Processor\SearchResultRetrieverProcessor;
use Madj2k\AiAssistantPremium\License\LicenseCheckInterface;
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

        $licenseService = $this->createStub(LicenseCheckInterface::class);
        $licenseService->method('isValid')->willReturn(true);

        (new SearchResultRetrieverProcessor(
            $this->createStub(PipelineLoggerInterface::class),
            $licenseService,
        ))
            ->process($context, $step);

        self::assertSame(['qdrant', 'Search results'], array_map(
            static fn (RetrievalGroup $group): string => $group->identifier,
            $context->getRetrieval()->getGroups(),
        ));
        self::assertSame('Visible search result content', $context->getRetrieval()->getDocuments()[0]->text);
    }

    public function testDoesNotProcessCapturedSearchResultsWhenLicenseIsInvalid(): void
    {
        $licenseService = $this->createStub(LicenseCheckInterface::class);
        $licenseService->method('isValid')->willReturn(false);

        $processor = new SearchResultRetrieverProcessor(
            $this->createStub(PipelineLoggerInterface::class),
            $licenseService,
        );

        self::assertFalse($processor->canProcess(
            new Context(
                new AssistantContext(),
                new Request('query', 'chat'),
                new History([]),
                new RetrievalResult(),
                new AnswerState(),
                new ProcessingTrace(),
            ),
            $this->createStub(AssistantPipelineStep::class),
        ));
    }

    public function testCanProcessCapturedSearchResultsWhenLicenseIsValid(): void
    {
        $licenseService = $this->createStub(LicenseCheckInterface::class);
        $licenseService->method('isValid')->willReturn(true);

        $context = new Context(
            new AssistantContext(),
            new Request('query', 'chat', runtimeSettings: [
                'search' => ['capturedResults' => ['results' => [['id' => 'search:1']]]],
            ]),
            new History([]),
            new RetrievalResult(),
            new AnswerState(),
            new ProcessingTrace(),
        );

        self::assertTrue((new SearchResultRetrieverProcessor(
            $this->createStub(PipelineLoggerInterface::class),
            $licenseService,
        ))->canProcess($context, $this->createStub(AssistantPipelineStep::class)));
    }

    public function testAppliesScoreThresholdAndRetrievalResultLimit(): void
    {
        $context = new Context(
            new AssistantContext(),
            new Request('query', 'chat', runtimeSettings: ['search' => ['capturedResults' => [
                'results' => [
                    ['id' => 'search:low', 'score' => 0.2, 'text' => 'low'],
                    ['id' => 'search:first', 'score' => 0.9, 'text' => 'first'],
                    ['id' => 'search:second', 'score' => 0.8, 'text' => 'second'],
                ],
            ]]]),
            new History([]),
            new RetrievalResult(),
            new AnswerState(),
            new ProcessingTrace(),
        );
        $step = new AssistantPipelineStep();
        $step->setMaxRetrievalResults(1);
        $step->setScoreThreshold(0.5);

        $licenseService = $this->createStub(LicenseCheckInterface::class);
        $licenseService->method('isValid')->willReturn(true);

        (new SearchResultRetrieverProcessor(
            $this->createStub(PipelineLoggerInterface::class),
            $licenseService,
        ))->process($context, $step);

        $documents = $context->getRetrieval()->getDocuments();
        self::assertCount(1, $documents);
        self::assertSame('search:first#excerpt-1', $documents[0]->id);
        self::assertSame(0.9, $documents[0]->score);
    }

    public function testFallsBackToBeginningWhenQueryDoesNotMatch(): void
    {
        $context = new Context(
            new AssistantContext(),
            new Request('missing', 'chat', runtimeSettings: ['search' => ['capturedResults' => [
                'effectiveQuery' => 'missing',
                'results' => [[
                    'id' => 'search:1',
                    'text' => 'Beginning of the result. The query is not present here.',
                ]],
            ]]]),
            new History([]),
            new RetrievalResult(),
            new AnswerState(),
            new ProcessingTrace(),
        );
        $step = new AssistantPipelineStep();
        $step->setMaxChunkCharacters(10);

        $licenseService = $this->createStub(LicenseCheckInterface::class);
        $licenseService->method('isValid')->willReturn(true);

        (new SearchResultRetrieverProcessor(
            $this->createStub(PipelineLoggerInterface::class),
            $licenseService,
        ))->process($context, $step);

        self::assertSame('Beginning ', $context->getRetrieval()->getDocuments()[0]->text);
    }

    public function testCreatesMultipleQueryFocusedExcerptsPerResult(): void
    {
        $retrieval = new RetrievalResult();
        $context = new Context(
            new AssistantContext(),
            new Request('TYPO3', 'chat', runtimeSettings: ['search' => ['capturedResults' => [
                'effectiveQuery' => 'TYPO3',
                'total' => 1,
                'results' => [[
                    'id' => 'search:1',
                    'score' => 1.0,
                    'text' => 'TYPO3 first relevant passage. ' . str_repeat('unrelated text. ', 8) . 'TYPO3 second relevant passage.',
                ]],
            ]]]),
            new History([]),
            $retrieval,
            new AnswerState(),
            new ProcessingTrace(),
        );
        $step = new AssistantPipelineStep();
        $step->setMaxRetrievalResults(1);
        $step->setMaxChunksPerResult(2);
        $step->setMaxChunkCharacters(40);

        $licenseService = $this->createStub(LicenseCheckInterface::class);
        $licenseService->method('isValid')->willReturn(true);

        (new SearchResultRetrieverProcessor(
            $this->createStub(PipelineLoggerInterface::class),
            $licenseService,
        ))->process($context, $step);

        $documents = $context->getRetrieval()->getDocuments();
        self::assertCount(2, $documents);
        self::assertStringContainsString('TYPO3', $documents[0]->text);
        self::assertStringContainsString('TYPO3', $documents[1]->text);
        self::assertLessThanOrEqual(40, mb_strlen($documents[0]->text));
        self::assertLessThanOrEqual(40, mb_strlen($documents[1]->text));
    }
}
