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
use Madj2k\AiAssistantPremium\Security\FrontendRequestTokenService;
use Madj2k\AiAssistant\Controller\AbstractController as CoreAbstractController;

/**
 * Class AbstractController
 *
 * Shared controller functionality for Premium frontend endpoints.
 *
 * @author Maximilian Fäßler <maximilian@faesslerweb.de>
 * @author Steffen Kroggel <developer@steffenkroggel.de>
 * @copyright Steffen Kroggel <developer@steffenkroggel.de>
 * @package Madj2k\AiAssistantPremium
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License, version 3 or later
 */
abstract class AbstractController extends CoreAbstractController
{
    /**
     * Constructor
     *
     * @param \Madj2k\AiAssistant\Assistant\Domain\Repository\AssistantProfileRepository $assistantProfileRepository
     * @param \Madj2k\AiAssistantPremium\Security\FrontendRequestTokenService $requestTokenService
     */
    public function __construct(
        AssistantProfileRepository $assistantProfileRepository,
        protected readonly FrontendRequestTokenService $requestTokenService,
    ) {
        parent::__construct($assistantProfileRepository);
    }


    /**
     * Creates unique request token
     *
     * @param int $assistantProfile
     * @param string $chatIdentifier
     * @return string
     */
    protected function createRequestToken(int $assistantProfile, string $chatIdentifier): string
    {
        if (!$this->isAllowedAssistantProfile($assistantProfile)) {
            return '';
        }

        $settings = $this->getPremiumSiteSettings();
        return $this->requestTokenService->create([
            'pageUid' => $this->getCurrentPageUid(),
            'assistantProfile' => $assistantProfile,
            'chatIdentifier' => $chatIdentifier,
        ], (int)($settings['requestTokenTtl'] ?? 7200));
    }


    /**
     * Loads settings for premium extension (for security settings)
     *
     * @return array
     */
    protected function getPremiumSiteSettings(): array
    {
        $settings = $this->resolveServerRequest()?->getAttribute('site')?->getSettings();
        $premiumSettings = $settings?->get('aiAssistantPremium', []) ?? [];

        if (!is_array($premiumSettings)) {
            return [
                'allowedAssistantProfiles' => $settings?->get('aiAssistantPremium.allowedAssistantProfiles', []) ?? [],
                'requestTokenTtl' => $settings?->get('aiAssistantPremium.requestTokenTtl', 7200) ?? 7200,
            ];
        }

        return $premiumSettings;
    }

    /**
     * @return int
     */
    protected function getCurrentPageUid(): int
    {
        $queryParameters = $this->resolveServerRequest()?->getQueryParams() ?? [];
        $requestPageUid = (int)($queryParameters['id'] ?? 0);
        if ($requestPageUid === 0) {
            $requestPageUid = (int)(
                $queryParameters['tx_aiassistantpremium_search']['id'] ?? 0
            );
        }
        if ($requestPageUid > 0) {
            return $requestPageUid;
        }

        $contentPageUid = (int)($this->currentContentObject?->data['pid'] ?? 0);
        if ($contentPageUid > 0) {
            return $contentPageUid;
        }

        $tsfePageUid = (int)($GLOBALS['TSFE']->id ?? 0);
        if ($tsfePageUid > 0) {
            return $tsfePageUid;
        }

        return (int)($this->resolveServerRequest()?->getQueryParams()['id'] ?? 0);
    }

    /**
     * Checks if given assistantProfile is actually allowed for the current site
     *
     * @param int $assistantProfile
     * @return bool
     */
    protected function isAllowedAssistantProfile(int $assistantProfile): bool
    {
        $allowed = $this->getPremiumSiteSettings()['allowedAssistantProfiles'] ?? [];
        if (is_string($allowed)) {
            $allowed = preg_split('/[,\s]+/', trim($allowed), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }

        return is_array($allowed) && in_array($assistantProfile, array_map('intval', $allowed), true);
    }
}
