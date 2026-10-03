<?php
declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

namespace Madj2k\AiAssistantPremium\Security;

use RuntimeException;

/**
 * Class FrontendRequestTokenService
 *
 * Creates and validates signed frontend capability tokens.
 *
 * @author Steffen Kroggel <developer@steffenkroggel.de>
 * @copyright Steffen Kroggel <developer@steffenkroggel.de>
 * @package Madj2k_SiteDefault
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License, version 3 or later
 */
final class FrontendRequestTokenService
{
    private const CONTEXT = 'madj2k/ai-assistant-premium/frontend-request/v1';


    /**
     * @param array $claims
     * @param int $ttl
     * @return string
     */
    public function create(array $claims, int $ttl): string
    {
        $claims['iat'] = time();
        $claims['exp'] = time() + max(60, $ttl);
        $payload = $this->encode($claims);
        return $payload . '.' . $this->sign($payload);
    }


    /**
     * @param string $token
     * @param array $expectedClaims
     * @return bool
     */
    public function isValid(string $token, array $expectedClaims): bool
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) {
            return false;
        }

        [$payload, $signature] = $parts;
        if (!hash_equals($this->sign($payload), $signature)) {
            return false;
        }

        try {
            $claims = json_decode($this->decode($payload), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return false;
        }

        if (!is_array($claims) || (int)($claims['exp'] ?? 0) < time()) {
            return false;
        }

        foreach ($expectedClaims as $key => $value) {
            if ((string)($claims[$key] ?? '') !== (string)$value) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array $claims
     * @return string
     */
    private function encode(array $claims): string
    {
        try {
            return $this->base64UrlEncode(json_encode($claims, JSON_THROW_ON_ERROR));
        } catch (\JsonException $exception) {
            throw new RuntimeException('Unable to encode frontend request token.', 0, $exception);
        }
    }


    /**
     * @param string $payload
     * @return string
     */
    private function sign(string $payload): string
    {
        $encryptionKey = (string)($GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] ?? '');
        if ($encryptionKey === '') {
            throw new RuntimeException('TYPO3 SYS/encryptionKey is not configured.');
        }

        return $this->base64UrlEncode(hash_hmac('sha256', self::CONTEXT . '.' . $payload, $encryptionKey, true));
    }


    /**
     * @param string $value
     * @return string
     */
    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }


    /**
     * @param string $value
     * @return string
     */
    private function decode(string $value): string
    {
        $padding = strlen($value) % 4;
        if ($padding !== 0) {
            $value .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if ($decoded === false) {
            throw new RuntimeException('Invalid frontend request token.');
        }

        return $decoded;
    }
}
