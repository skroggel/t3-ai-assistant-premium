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

namespace Madj2k\AiAssistantPremium\Controller;

use Madj2k\AiAssistant\Controller\AbstractController as AiAssistantAbstractController;
use Madj2k\AiAssistant\Assistant\Domain\Repository\AssistantProfileRepository;
use Madj2k\AiAssistant\Assistant\Frontend\ChatOptionsResolver;
use Madj2k\AiCore\Assistant\DTO\AssistantRequest;
use Madj2k\AiAssistantPremium\License\LicenseService;
use Madj2k\AiAssistant\Assistant\Service\FrontendRequestTokenService;
use Madj2k\AiCore\Assistant\Application\Orchestrator;
use Madj2k\AiCore\Assistant\DTO\DirectInteraction;
use Madj2k\AiCore\Exception\AppException;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Http\JsonResponse;


/**
 * Class JsonController
 *
 * Generic JSON entry point for complete AI Assistant turns.
 *
 *
 * @author Maximilian Fäßler <maximilian@faesslerweb.de>
 * @author Steffen Kroggel <developer@steffenkroggel.de>
 * @copyright Steffen Kroggel <developer@steffenkroggel.de>
 * @package Madj2k\AiAssistantPremium
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License, version 3 or later
 */
final class JsonController extends AiAssistantAbstractController
{

    /**
     * Constructor
     *
     * @param \Madj2k\AiAssistant\Assistant\Domain\Repository\AssistantProfileRepository $assistantProfileRepository
     * @param \Madj2k\AiAssistantPremium\License\LicenseService $licenseService
     * @param \Madj2k\AiAssistant\Assistant\Frontend\ChatOptionsResolver $chatOptionsResolver
     * @param \Madj2k\AiCore\Assistant\Application\Orchestrator $orchestrator
     * @param \Psr\Log\LoggerInterface $logger
     * @param \Madj2k\AiAssistant\Assistant\Service\FrontendRequestTokenService $requestTokenService
     */
    public function __construct(
        AssistantProfileRepository $assistantProfileRepository,
        private readonly LicenseService $licenseService,
        private readonly ChatOptionsResolver $chatOptionsResolver,
        private readonly Orchestrator $orchestrator,
        private readonly LoggerInterface $logger,
        FrontendRequestTokenService $requestTokenService,
    ) {
        parent::__construct(
            $assistantProfileRepository,
            $requestTokenService,
        );
    }


    /**
     * Executes one complete assistant turn and returns JSON.
     *
     * @param string $query
     * @param int $assistantProfile
     * @param string $chatIdentifier
     * @param string $settingsJson
     * @param int $startTimestamp
     * @param string $requestToken
     * @param string $userLanguage
     * @param string $directInteraction
     * @return \Psr\Http\Message\ResponseInterface
     * @throws \TYPO3\CMS\Core\Cache\Exception\NoSuchCacheException
     */
    public function assistantAction(
        string $query = '',
        int $startTimestamp = 0,
        int $assistantProfile = 0,
        string $chatIdentifier = '',
        string $settingsJson = '{}',
        string $requestToken = '',
        string $userLanguage = '',
        string $directInteraction = '',
    ): ResponseInterface {

        try {
            if (!$this->licenseService->isValid()) {
                return new JsonResponse(['error' => 'Assistant profile is not allowed.'], 403);
            }

            if (!$this->isValidFrontendRequest($requestToken, $assistantProfile, $chatIdentifier, $settingsJson)) {
                $this->logger->warning('Generic assistant JSON request rejected: invalid token.', [
                    'page_uid' => $this->getCurrentPageUid(),
                    'assistant_profile' => $assistantProfile,
                    'chat_identifier' => $chatIdentifier,
                    'token_present' => $requestToken !== '',
                ]);
                return new JsonResponse(['error' => 'Invalid assistant request token.'], 403);
            }

            $profile = $this->resolveAssistantProfile($assistantProfile);
            if ($profile === null) {
                return new JsonResponse(['error' => 'Assistant profile was not found.'], 404);
            }

            $runtimeSettings = $this->resolveRuntimeSettings($settingsJson);

            if ($chatIdentifier === '' || $startTimestamp === 0) {
                throw new AppException('No chat identifier specified');
            }

            $isLanguageConfirmation = $directInteraction === 'language_confirmation';
            if ($directInteraction !== '' && !$isLanguageConfirmation) {
                throw new AppException('Unsupported direct interaction');
            }

            $chatOptions = $this->chatOptionsResolver->resolve(
                $runtimeSettings,
                $this->resolveSiteLanguage(),
                $userLanguage,
            );

            $assistantRequest = new AssistantRequest(
                query: $isLanguageConfirmation ? $chatOptions->responseLanguage : trim($query),
                startTimestamp: $startTimestamp > 0 ? $startTimestamp : time(),
                assistantProfile: $profile,
                chatIdentifier: $chatIdentifier,
                serverRequest: $this->resolveServerRequest(),
                chatOptions: $chatOptions,
                runtimeSettings: $runtimeSettings,
            );

            if ($isLanguageConfirmation) {
                $response = $this->orchestrator->handleDirect(
                    $assistantRequest,
                    new DirectInteraction(
                        instruction: 'Write exactly one short sentence confirming that all following answers will use the language named by the user. Write the sentence in that language and mention only its natural language name. Interpret language, locale and regional codes when provided, but never reproduce or mention those codes in the answer.',
                        maxTokens: 60,
                        remember: false,
                    ),
                );
            } else {
                $response = $this->orchestrator->handle($assistantRequest);
            }

        } catch (\Throwable $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], 500);
        }

        return new JsonResponse([
            'answer' => $response->answer,
            'context' => $response->context,
            'debug' => $response->debug,
            'assistantProfile' => $assistantProfile,
            'chatIdentifier' => $chatIdentifier,
        ]);
    }

}
