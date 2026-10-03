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
use Madj2k\AiAssistantPremium\License\LicenseService;
use Madj2k\AiAssistantPremium\Search\Middleware\SearchQueryMiddleware;
use Madj2k\AiAssistant\Assistant\Service\FrontendRequestTokenService;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

/**
 * Class SearchController
 *
 * Renders Premium search metadata and Summary-plugin configuration.
 *
 * @author Maximilian Fäßler <maximilian@faesslerweb.de>
 * @author Steffen Kroggel <developer@steffenkroggel.de>
 * @copyright Steffen Kroggel <developer@steffenkroggel.de>
 * @package Madj2k\AiAssistantPremium
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License, version 3 or later
 */
class SearchController extends AiAssistantAbstractController
{
    /**
     * Constructor
     *
     * @param \Madj2k\AiAssistant\Assistant\Domain\Repository\AssistantProfileRepository $assistantProfileRepository
     * @param \Madj2k\AiAssistantPremium\License\LicenseService $licenseService
     * @param \Madj2k\AiAssistant\Assistant\Frontend\ChatOptionsResolver $chatOptionsResolver
     * @param \Psr\Log\LoggerInterface $logger
     * @param \Madj2k\AiAssistant\Assistant\Service\FrontendRequestTokenService $requestTokenService
     */
    public function __construct(
        AssistantProfileRepository $assistantProfileRepository,
        private readonly LicenseService $licenseService,
        private readonly ChatOptionsResolver $chatOptionsResolver,
        private readonly LoggerInterface $logger,
        FrontendRequestTokenService $requestTokenService,
    ) {
        parent::__construct($assistantProfileRepository, $requestTokenService);
    }


    /**
     * Renders metadata for the native/Vue search enhancer
     *
     * @return \Psr\Http\Message\ResponseInterface
     * @throws \TYPO3\CMS\Core\Cache\Exception\NoSuchCacheException
     */
    public function indexAction(): ResponseInterface
    {
        if (!$this->licenseService->isValid()) {
            return $this->htmlResponse('');
        }

        $metadata = $this->getSearchMetadata();
        $chatIdentifier = $this->resolveChatIdentifier($metadata);
        $assistantProfile = (int)($this->settings['assistantProfile'] ?? 0);
        $this->logDisallowedAssistantProfile($assistantProfile);
        $this->view->assignMultiple([
            'pageUid' => $this->getCurrentPageUid(),
            'chatIdentifier' => $chatIdentifier,
            'requestToken' => $this->createRequestToken(
                $assistantProfile,
                $chatIdentifier,
            ),
        ]);

        return $this->htmlResponse();
    }


    /**
     * Renders the Summary-plugin configuration for the Vue Summary element.
     *
     * @return \Psr\Http\Message\ResponseInterface
     * @throws \TYPO3\CMS\Core\Cache\Exception\NoSuchCacheException
     */
    public function searchSummaryAction(): ResponseInterface
    {
        if (!$this->licenseService->isValid()) {
            return $this->htmlResponse('');
        }

        $metadata = $this->getSearchMetadata();
        $chatIdentifier = $this->resolveChatIdentifier($metadata);
        $chatOptions = $this->chatOptionsResolver->resolve($this->settings, $this->resolveSiteLanguage());
        $assistantProfile = (int)($this->settings['assistantProfile'] ?? 0);
        $this->logDisallowedAssistantProfile($assistantProfile);
        $this->view->assignMultiple([
            'pageUid' => $this->getCurrentPageUid(),
            'chatIdentifier' => $chatIdentifier,
            'integration' => (string)($this->settings['integration'] ?? 'ke_search'),
            'searchString' => trim((string)($metadata['originalQuery'] ?? '')),
            'startTimestamp' => time(),
            'settingsJson' => $this->jsonEncodeSettings($this->settings),
            'chatOptionsJson' => $this->jsonEncodeSettings(
                $this->chatOptionsResolver->toFrontendOptions($chatOptions, $this->settings)
            ),
            'labelsJson' => $this->jsonEncodeSettings($this->getFrontendLabels()),
            'requestToken' => $this->createRequestToken(
                $assistantProfile,
                $chatIdentifier,
            ),
        ]);

        return $this->htmlResponse();
    }


    /**
     * Logs a configuration hint when a frontend profile is not allowlisted.
     */
    private function logDisallowedAssistantProfile(int $assistantProfile): void
    {
        if ($assistantProfile === 0 || $this->isAllowedAssistantProfile($assistantProfile)) {
            return;
        }

        $this->logger->warning('Premium search assistant profile is not allowed for this site.', [
            'assistant_profile' => $assistantProfile,
            'allowed_assistant_profiles' => $this->getAssistantSiteSettings()['allowedAssistantProfiles'] ?? [],
            'page_uid' => $this->getCurrentPageUid(),
        ]);
    }


    /**
     * Returns all relevant frontend labels
     *
     * @return array
     */
    private function getFrontendLabels(): array
    {
        $keys = [
            'errorMessage' => 'templates_index_index.error_message',
            'chatLabel' => 'templates_index_index.chat_history',
            'userLabel' => 'templates_index_index.user_label',
            'assistantLabel' => 'templates_index_index.assistant_label',
            'consentMessage' => 'templates_index_index.consent_message',
            'consentLabel' => 'templates_index_index.consent_button',
            'inputPlaceholder' => 'templates_index_index.input_placeholder',
            'submitLabel' => 'templates_index_index.submit',
            'languageLabel' => 'templates_index_index.response_language',
            'siteLanguageLabel' => 'templates_index_index.use_site_language',
            'browserLanguageLabel' => 'templates_index_index.use_browser_language',
            'languageApplyLabel' => 'templates_index_index.apply_language',
            'languagePlaceholder' => 'templates_index_index.response_language_placeholder',
            'languageConfirmationFallback' => 'templates_index_index.language_confirmation_fallback',
        ];

        $labels = [];
        foreach ($keys as $name => $key) {
            $labels[$name] = (string)(LocalizationUtility::translate($key, 'ai_assistant') ?? '');
        }

        return $labels;
    }


    /**
     * @return array
     */
    private function getSearchMetadata(): array
    {
        $request = $this->resolveServerRequest();
        $metadata = $request?->getQueryParams()[SearchQueryMiddleware::CONTROL_PARAMETER] ?? [];

        return is_array($metadata) ? $metadata : [];
    }


    /**
     * @param array $metadata
     * @return string
     */
    private function resolveChatIdentifier(array $metadata): string
    {
        $chatIdentifier = trim((string)($metadata['chatIdentifier'] ?? ''));
        return $chatIdentifier !== '' ? $chatIdentifier : $this->createChatIdentifier();
    }
}
