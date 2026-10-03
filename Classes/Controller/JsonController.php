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

use Madj2k\AiAssistant\Assistant\Domain\Repository\AssistantProfileRepository;
use Madj2k\AiAssistant\Assistant\Frontend\ChatOptionsResolver;
use Madj2k\AiAssistantPremium\License\LicenseService;
use Madj2k\AiAssistantPremium\Security\FrontendRequestTokenService;
use Madj2k\AiCore\Assistant\Application\Orchestrator;
use Madj2k\AiCore\Assistant\DTO\AssistantRequest;
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
final class JsonController extends SearchController
{

    /**
     * Constructor
     *
     * @param \Madj2k\AiAssistant\Assistant\Domain\Repository\AssistantProfileRepository $assistantProfileRepository
     * @param \Madj2k\AiAssistantPremium\License\LicenseService $licenseService
     * @param \Madj2k\AiAssistant\Assistant\Frontend\ChatOptionsResolver $chatOptionsResolver
     * @param \Madj2k\AiCore\Assistant\Application\Orchestrator $orchestrator
     * @param \Psr\Log\LoggerInterface $logger
     * @param \Madj2k\AiAssistantPremium\Security\FrontendRequestTokenService $requestTokenService
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
            $licenseService,
            $chatOptionsResolver,
            $logger,
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
     * @param string $token
     * @param string $userLanguage
     * @return \Psr\Http\Message\ResponseInterface
     * @throws \TYPO3\CMS\Core\Cache\Exception\NoSuchCacheException
     */
    public function assistantAction(
        string $query = '',
        int $assistantProfile = 0,
        string $chatIdentifier = '',
        string $settingsJson = '{}',
        string $token = '',
        string $userLanguage = '',
    ): ResponseInterface {

        if (!$this->licenseService->isValid() || !$this->isAllowedAssistantProfile($assistantProfile)) {
            return new JsonResponse(['error' => 'Assistant profile is not allowed.'], 403);
        }

        if (!$this->requestTokenService->isValid($token, [
            'pageUid' => $this->getCurrentPageUid(),
            'assistantProfile' => $assistantProfile,
            'chatIdentifier' => $chatIdentifier,
        ])) {
            $this->logger->warning('Generic assistant JSON request rejected: invalid token.', [
                'page_uid' => $this->getCurrentPageUid(),
                'assistant_profile' => $assistantProfile,
                'chat_identifier' => $chatIdentifier,
                'token_present' => $token !== '',
            ]);
            return new JsonResponse(['error' => 'Invalid assistant request token.'], 403);
        }

        $profile = $this->assistantProfileRepository->findByUid($assistantProfile);
        if ($profile === null) {
            return new JsonResponse(['error' => 'Assistant profile was not found.'], 404);
        }

        $runtimeSettings = json_decode($settingsJson, true);
        $runtimeSettings = is_array($runtimeSettings) ? $runtimeSettings : [];

        try {
            $chatOptions = $this->chatOptionsResolver->resolve(
                $runtimeSettings,
                $this->resolveSiteLanguage(),
                $userLanguage,
            );
            $response = $this->orchestrator->handle(new AssistantRequest(
                query: trim($query),
                startTimestamp: time(),
                assistantProfile: $profile,
                chatIdentifier: $chatIdentifier,
                serverRequest: $this->resolveServerRequest(),
                chatOptions: $chatOptions,
                runtimeSettings: $runtimeSettings,
            ));
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
