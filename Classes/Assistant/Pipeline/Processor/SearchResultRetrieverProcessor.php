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

namespace Madj2k\AiAssistantPremium\Assistant\Pipeline\Processor;

use Madj2k\AiCore\Assistant\Configuration\PipelineStepConfigurationInterface;
use Madj2k\AiCore\Assistant\Context\Context;
use Madj2k\AiCore\Assistant\DTO\RetrievalDocument;
use Madj2k\AiCore\Assistant\Log\PipelineLogMetaData;
use Madj2k\AiCore\Assistant\Log\PipelineLoggerInterface;
use Madj2k\AiCore\Assistant\Pipeline\Processor\AbstractRetrieverProcessor;
use Madj2k\AiAssistantPremium\License\LicenseCheckInterface;

/**
 * Class SearchResultRetrieverProcessor
 *
 * Converts search results captured by the rendered page into retrieval documents.
 *
 * @author Maximilian Fäßler <maximilian@faesslerweb.de>
 * @copyright Steffen Kroggel <developer@steffenkroggel.de>, Maximilian Fäßler <maximilian@faesslerweb.de>
 * @package Madj2k\AiAssistantPremium
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License, version 3
 */
final readonly class SearchResultRetrieverProcessor extends AbstractRetrieverProcessor
{
    /**
     * Processor identifier.
     *
     * @var string
     */
    public const IDENTIFIER = 'ai_assistant_premium.search_result_retriever';


    /**
     * Constructor.
     *
     * @param PipelineLoggerInterface $pipelineLogger Pipeline logger.
     */
    public function __construct(
        private PipelineLoggerInterface $pipelineLogger,
         private LicenseCheckInterface $licenseService,
    ) {
    }

    /**
     * Returns the processor identifier.
     *
     * @return string Processor identifier.
     */
    public function getIdentifier(): string
    {
        return self::IDENTIFIER;
    }

    /**
     * Processes only summary requests that contain captured search results.
     *
     * @param Context $context Current assistant context.
     * @param PipelineStepConfigurationInterface $step Current pipeline step.
     * @return bool Whether captured search results are available.
     */
    public function canProcess(Context $context, PipelineStepConfigurationInterface $step): bool
    {
        if (!$this->licenseService->isValid()) {
            return false;
        }

        $payload = $context->getRequest()->getRuntimeSetting('search.capturedResults', []);
        return is_array($payload) && is_array($payload['results'] ?? null) && $payload['results'] !== [];
    }

    /**
     * Maps the captured result payload without executing another search.
     *
     * @param Context $context Current assistant context.
     * @param PipelineStepConfigurationInterface $step Current pipeline step.
     * @param PipelineLogMetaData|null $logContext Optional pipeline log metadata.
     * @return void
     */
    public function process(
        Context $context,
        PipelineStepConfigurationInterface $step,
        ?PipelineLogMetaData $logContext = null,
    ): void {
        if (!$this->licenseService->isValid()) {
            return;
        }

        $payload = $context->getRequest()->getRuntimeSetting('search.capturedResults', []);
        $payload = is_array($payload) ? $payload : [];
        $rows = is_array($payload['results'] ?? null) ? $payload['results'] : [];
        $effectiveQuery = (string)($payload['effectiveQuery'] ?? $context->getCurrentQuery());
        $documents = $this->mapDocuments($rows, $step, $effectiveQuery);

        $this->storeRetrievalGroup(
            $context,
            $step,
            self::IDENTIFIER,
            $documents,
            [$payload],
            $effectiveQuery,
        );

        if ($logContext instanceof PipelineLogMetaData) {
            $this->pipelineLogger->logRetrievalResponse($logContext, $step->getTitle(), self::IDENTIFIER, [
                'payload' => $payload,
                'result_count' => count($documents),
                'total' => (int)($payload['total'] ?? count($documents)),
                'integration' => (string)($payload['integration'] ?? ''),
            ]);
        }
    }

    /**
     * Maps captured search result rows to retrieval documents.
     *
     * @param array<int, mixed> $rows Captured search result rows.
     * @param PipelineStepConfigurationInterface $step Current pipeline step.
     * @param string $query Effective search query.
     * @return array<int, RetrievalDocument> Normalized retrieval documents.
     */
    private function mapDocuments(
        array $rows,
        PipelineStepConfigurationInterface $step,
        string $query,
    ): array
    {
        $maximum = max(0, $step->getMaxRetrievalResults());
        $threshold = $step->getScoreThreshold();
        $documents = [];
        $processedResults = 0;

        if ($maximum === 0) {
            return [];
        }

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $score = is_numeric($row['score'] ?? null) ? (float)$row['score'] : 0.0;
            if ($threshold > 0.0 && $score < $threshold) {
                continue;
            }

            $identifier = trim((string)($row['id'] ?? $row['source_identifier'] ?? ''));
            $text = $this->extractText($row);
            if ($identifier === '' || $text === '') {
                continue;
            }

            $excerpts = $this->extractRelevantExcerpts($text, $query, $step);
            foreach ($excerpts as $index => $excerpt) {
                $documents[] = new RetrievalDocument(
                    id: $identifier . '#excerpt-' . ($index + 1),
                    score: $score,
                    text: $excerpt,
                    documentMetadata: $this->extractMetadata($row, $step, $row),
                );
            }

            $processedResults++;
            if ($processedResults >= $maximum) {
                break;
            }
        }

        return $documents;
    }

    /**
     * Extracts the best query-focused windows from one search result.
     *
     * @return array<int, string>
     */
    private function extractRelevantExcerpts(
        string $text,
        string $query,
        PipelineStepConfigurationInterface $step,
    ): array {
        $maximumCharacters = $step->getMaxChunkCharacters();
        $maximumExcerpts = max(1, $step->getMaxChunksPerResult());
        if ($maximumCharacters <= 0) {
            return [$text];
        }

        $terms = array_values(array_unique(array_filter(
            preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($query)) ?: [],
            static fn (string $term): bool => mb_strlen($term) > 1,
        )));
        if ($terms === []) {
            return [mb_substr($text, 0, $maximumCharacters)];
        }

        $textLength = mb_strlen($text);
        $candidates = [];
        foreach ($terms as $term) {
            $offset = 0;
            while (($position = mb_stripos($text, $term, $offset)) !== false) {
                $start = max(0, $position - intdiv($maximumCharacters, 2));
                $excerpt = mb_substr($text, $start, $maximumCharacters);
                $matchedTerms = 0;
                foreach ($terms as $candidateTerm) {
                    if (mb_stripos($excerpt, $candidateTerm) !== false) {
                        $matchedTerms++;
                    }
                }
                $candidates[] = [
                    'start' => $start,
                    'end' => min($textLength, $start + $maximumCharacters),
                    'score' => $matchedTerms,
                    'text' => $excerpt,
                ];
                $offset = $position + max(1, mb_strlen($term));
            }
        }

        if ($candidates === []) {
            return [mb_substr($text, 0, $maximumCharacters)];
        }

        usort($candidates, static fn (array $left, array $right): int =>
            $right['score'] <=> $left['score'] ?: $left['start'] <=> $right['start']
        );

        $selected = [];
        foreach ($candidates as $candidate) {
            $overlaps = false;
            foreach ($selected as $item) {
                if ($candidate['start'] < $item['end'] && $candidate['end'] > $item['start']) {
                    $overlaps = true;
                    break;
                }
            }
            if ($overlaps) {
                continue;
            }
            $selected[] = $candidate;
            if (count($selected) >= $maximumExcerpts) {
                break;
            }
        }

        usort($selected, static fn (array $left, array $right): int => $left['start'] <=> $right['start']);

        return array_map(
            static fn (array $candidate): string => trim($candidate['text']),
            $selected,
        );
    }
}
