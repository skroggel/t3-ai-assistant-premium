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

namespace Madj2k\AiAssistantPremium\License;

use Madj2k\AiAssistantPremium\License\Exception\LicenseRequiredException;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * Class LicenseService
 *
 * Validates the configured premium license against a static license file.
 *
 * Successful validation results are cached for 24 hours.
 *
 * If the license server is temporarily unavailable because of a network
 * failure or server-side error, the last successful validation may be used
 * for up to seven days.
 *
 * A HTTP 404 response is treated as an authoritative invalidation and
 * immediately disables the premium functionality.
 *
 * @author Maximilian Fäßler <maximilian@faesslerweb.de>
 * @author Steffen Kroggel <developer@steffenkroggel.de>
 * @copyright Steffen Kroggel <developer@steffenkroggel.de>
 * @package Madj2k\AiAssistantPremium
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License, version 3 or later
 */
final class LicenseService
{
    /**
     * TYPO3 extension key.
     */
    public const EXTENSION_KEY = 'ai_assistant_premium';

    /**
     * Optional environment variable used to override the configured license key.
     */
    public const LICENSE_ENVIRONMENT_VARIABLE = 'AI_ASSISTANT_PREMIUM_LICENSE';

    /**
     * Base URL of the remote license storage.
     */
    public const LICENSE_URL_PREFIX =
        'https://license.mediafiles.de/t3-ai-assistant-premium/';

    /**
     * TYPO3 cache used to store the current license state.
     */
    private const CACHE_NAME = 'runtime';

    /**
     * Minimum interval between successful remote license checks.
     *
     * 24 hours in seconds.
     */
    private const CHECK_INTERVAL = 86400;

    /**
     * Maximum grace period after the last successful license validation.
     *
     * Seven days in seconds.
     */
    private const GRACE_PERIOD = 604800;

    /**
     * Runtime-local license state.
     *
     * Prevents repeated checks during the same PHP request.
     */
    private ?bool $runtimeValidity = null;

    /**
     * @param ExtensionConfiguration $extensionConfiguration TYPO3 extension configuration
     * @param RequestFactory $requestFactory TYPO3 HTTP request factory
     * @param CacheManager $cacheManager TYPO3 cache manager
     */
    public function __construct(
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly RequestFactory $requestFactory,
        private readonly CacheManager $cacheManager,
    ) {
    }


    /**
     * Checks whether the configured premium license is valid.
     *
     * Validation behaviour:
     *
     * - Cached successful checks are reused for 24 hours.
     * - HTTP 200 marks the license as valid.
     * - HTTP 404 marks the license as invalid immediately.
     * - Network failures and server-side errors may use the last successful
     *   validation for up to seven days.
     *
     * @return bool True if the premium license is currently considered valid
     * @throws \TYPO3\CMS\Core\Cache\Exception\NoSuchCacheException
     */
    public function isValid(): bool
    {

        if ($this->runtimeValidity !== null) {
            return $this->runtimeValidity;
        }

        $licenseKey = $this->getLicenseKey();

        if ($licenseKey === '') {
            return $this->runtimeValidity = false;
        }

        $cache = $this->cacheManager->getCache(self::CACHE_NAME);
        $cacheIdentifier = $this->getCacheIdentifier($licenseKey);

        $cachedState = $cache->get($cacheIdentifier);

        $lastSuccess = 0;

        if (is_array($cachedState)) {
            $lastCheck = (int)($cachedState['lastCheck'] ?? 0);
            $lastSuccess = (int)($cachedState['lastSuccess'] ?? 0);
            $valid = (bool)($cachedState['valid'] ?? false);

            if (
                $valid
                && $lastCheck > 0
                && (time() - $lastCheck) < self::CHECK_INTERVAL
            ) {
                return $this->runtimeValidity = true;
            }
        }

        $url = $this->buildLicenseUrl($licenseKey);

        try {
            $response = $this->requestFactory->request(
                $url,
                'GET',
                [
                    'allow_redirects' => false,
                    'connect_timeout' => 2.0,
                    'timeout' => 3.0,
                    'http_errors' => false,
                    'headers' => [
                        'Accept' => 'text/plain,*/*;q=0.1',
                        'User-Agent' =>
                            'TYPO3 AI Assistant Premium License Check',
                    ],
                ],
            );

            $statusCode = $response->getStatusCode();

            if ($statusCode === 200) {
                $now = time();

                $cache->set(
                    $cacheIdentifier,
                    [
                        'valid' => true,
                        'lastCheck' => $now,
                        'lastSuccess' => $now,
                    ],
                    [],
                    self::GRACE_PERIOD,
                );

                return $this->runtimeValidity = true;
            }

            if ($statusCode === 404) {
                $cache->set(
                    $cacheIdentifier,
                    [
                        'valid' => false,
                        'lastCheck' => time(),
                        'lastSuccess' => 0,
                    ],
                    [],
                    self::CHECK_INTERVAL,
                );

                return $this->runtimeValidity = false;
            }

            return $this->runtimeValidity = $this->isWithinGracePeriod(
                $lastSuccess
            );
        } catch (\Throwable) {
            return $this->runtimeValidity = $this->isWithinGracePeriod(
                $lastSuccess
            );
        }
    }

    /**
     * Ensures that a valid premium license is available.
     *
    /**
     * @return void
     * @throws \TYPO3\CMS\Core\Cache\Exception\NoSuchCacheException
     * @throws \Madj2k\AiAssistantPremium\License\Exception\LicenseRequiredException
     */
    public function requireValidLicense(): void
    {
        if (!$this->isValid()) {
            throw new LicenseRequiredException(
                'AI Assistant Premium requires a valid license key.',
                1799674981,
            );
        }
    }

    /**
     * Returns the configured license key.
     *
     * The environment variable takes precedence over the TYPO3 extension
     * configuration.
     *
     * @return string Configured license key or an empty string if none exists
     */
    public function getLicenseKey(): string
    {
        $environmentValue = getenv(
            self::LICENSE_ENVIRONMENT_VARIABLE
        );

        if (
            is_string($environmentValue)
            && trim($environmentValue) !== ''
        ) {
            return trim($environmentValue);
        }

        try {
            $value = $this->extensionConfiguration->get(
                self::EXTENSION_KEY,
                'licenseKey'
            );
        } catch (\Throwable) {
            return '';
        }

        return is_scalar($value)
            ? trim((string)$value)
            : '';
    }

    /**
     * Returns the complete remote license URL for the configured key.
     *
     * @return string License URL or an empty string if no key is configured
     */
    public function getLicenseUrl(): string
    {
        $licenseKey = $this->getLicenseKey();

        if ($licenseKey === '') {
            return '';
        }

        return $this->buildLicenseUrl($licenseKey);
    }

    /**
     * Builds the remote license URL for a license key.
     *
     * The filename is generated from the SHA-256 hash of the trimmed
     * license key.
     *
     * @param string $licenseKey License key
     *
     * @return string Complete remote license URL
     */
    private function buildLicenseUrl(string $licenseKey): string
    {
        return self::LICENSE_URL_PREFIX
            . hash('sha256', trim($licenseKey))
            . '.licence';
    }

    /**
     * Generates the TYPO3 cache identifier for a license key.
     *
     * The raw license key is never stored as part of the cache identifier.
     *
     * @param string $licenseKey License key
     *
     * @return string Cache identifier
     */
    private function getCacheIdentifier(string $licenseKey): string
    {
        return 'ai_assistant_premium_license_'
            . hash('sha256', trim($licenseKey));
    }

    /**
     * Checks whether the last successful validation is still within
     * the configured grace period.
     *
     * @param int $lastSuccess Unix timestamp of the last successful check
     *
     * @return bool True if the grace period is still active
     */
    private function isWithinGracePeriod(int $lastSuccess): bool
    {
        if ($lastSuccess <= 0) {
            return false;
        }

        return (time() - $lastSuccess) <= self::GRACE_PERIOD;
    }
}


