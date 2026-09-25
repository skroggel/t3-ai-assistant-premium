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

namespace Madj2k\AiAssistantPremium\Search\Service;

use Madj2k\AiAssistant\Assistant\Domain\Model\AssistantProfile;
use Madj2k\AiAssistant\Assistant\Domain\Repository\AssistantProfileRepository;
use Madj2k\AiCore\Assistant\Context\ContextFactory;
use Madj2k\AiCore\Assistant\DTO\AssistantRequest;
use Madj2k\AiCore\Assistant\DTO\ChatOptions;
use Madj2k\AiCore\Assistant\Enum\AssistantPipelineProcessorType;
use Madj2k\AiCore\Assistant\Log\PipelineLoggerInterface;
use Madj2k\AiCore\Assistant\Pipeline\Registry\ProcessorRegistry;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

/**
 * Class QueryOptimizer
 *
 * Runs a search term through a configured assistant profile.
 *
 * @author Maximilian Fäßler <maximilian@faesslerweb.de>
 * @author Steffen Kroggel <developer@steffenkroggel.de>
 * @copyright Steffen Kroggel <developer@steffenkroggel.de>, Maximilian Fäßler <maximilian@faesslerweb.de>
 * @package Madj2k\AiAssistantPremium
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License, version 3
 */
final readonly class QueryOptimizer
{

    /**
     * @param ContextFactory $contextFactory
     * @param ProcessorRegistry $processorRegistry
     * @param AssistantProfileRepository $assistantProfileRepository
     * @param PipelineLoggerInterface $pipelineLogger
     * @param LoggerInterface $logger
     */
    public function __construct(
        private ContextFactory $contextFactory,
        private ProcessorRegistry $processorRegistry,
        private AssistantProfileRepository $assistantProfileRepository,
        private PipelineLoggerInterface $pipelineLogger,
        private LoggerInterface $logger,
    ) {
    }


    /**
     * Returns the optimized term or the original term when optimization fails.
     */
    public function optimize(
        string $query,
        int $profileUid,
        string $chatIdentifier,
        string $integration,
        ServerRequestInterface $request,
    ): string {
        $query = trim($query);
        if ($query === '' || $profileUid <= 0) {
            return $query;
        }

        try {
            $profile = $this->assistantProfileRepository->findByUid($profileUid);
            if (!$profile instanceof AssistantProfile) {
                throw new \RuntimeException(sprintf('Assistant profile %d was not found.', $profileUid));
            }

            $assistantRequest = new AssistantRequest(
                query: $query,
                startTimestamp: time(),
                assistantProfile: $profile,
                chatIdentifier: $chatIdentifier . ':query-optimizer',
                serverRequest: $request,
                chatOptions: new ChatOptions(),
                runtimeSettings: [
                    'search' => [
                        'phase' => 'query-optimization',
                        'integration' => $integration,
                    ],
                ],
            );
            $context = $this->contextFactory->create($assistantRequest, []);
            $logMetaData = $this->pipelineLogger->createMetaData($assistantRequest, 'search-query-optimization');

            foreach ($profile->getChatPipelineSteps() as $step) {
                if ($step->getType() !== AssistantPipelineProcessorType::QueryOptimizer) {
                    continue;
                }

                $processor = $this->processorRegistry->get($step->getProcessorIdentifier(), $step->getType());
                if ($processor->canProcess($context, $step)) {
                    $processor->process($context, $step, $logMetaData);
                }
            }

            $optimizedQuery = trim($context->getCurrentQuery());
            return $optimizedQuery !== '' ? $optimizedQuery : $query;
        } catch (\Throwable $exception) {
            $this->logger->warning('Search query optimization failed; using the original query.', [
                'exception' => $exception,
                'integration' => $integration,
                'profile_uid' => $profileUid,
            ]);

            return $query;
        }
    }
}
