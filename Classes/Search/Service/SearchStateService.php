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

namespace Madj2k\AiAssistantPremium\Search\Service;
use RuntimeException;

/**
 * Class SearchStateService
 *
 * @author Maximilian Fäßler <maximilian@faesslerweb.de>
 * @author Steffen Kroggel <developer@steffenkroggel.de>
 * @copyright Steffen Kroggel <developer@steffenkroggel.de>
 * @package Madj2k\AiAssistantPremium
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License, version 3 or later
 */
final readonly class SearchStateService
{
    private const VERSION = 1;
    private const CIPHER = 'aes-256-gcm';
    private const IV_LENGTH = 12;
    private const TAG_LENGTH = 16;
    private const KEY_CONTEXT = 'madj2k/ai-assistant-premium/search-state/v1';

    /**
     * @param array $state
     * @return string
     * @throws \JsonException
     * @throws \Random\RandomException
     */
    public function encode(array $state): string
    {
        if ($state === []) {
            throw new RuntimeException('Search state must not be empty.');
        }

        $payload = json_encode(
            $state,
            JSON_THROW_ON_ERROR
            | JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
        );

        $iv = random_bytes(self::IV_LENGTH);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $payload,
            self::CIPHER,
            $this->getEncryptionKey(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_LENGTH,
        );

        if ($ciphertext === false || strlen($tag) !== self::TAG_LENGTH) {
            throw new RuntimeException('Unable to encrypt search state.');
        }

        return $this->base64UrlEncode(
            chr(self::VERSION) . $iv . $tag . $ciphertext
        );
    }

    /**
     * @param string $token
     * @return array
     * @throws \JsonException
     */
    public function decode(string $token): array
    {
        if ($token === '') {
            throw new RuntimeException('Search state token must not be empty.');
        }

        $binary = $this->base64UrlDecode($token);

        if (strlen($binary) < 1 + self::IV_LENGTH + self::TAG_LENGTH + 1) {
            throw new RuntimeException('Invalid search state token.');
        }

        if (ord($binary[0]) !== self::VERSION) {
            throw new RuntimeException('Unsupported search state version.');
        }

        $offset = 1;
        $iv = substr($binary, $offset, self::IV_LENGTH);
        $offset += self::IV_LENGTH;

        $tag = substr($binary, $offset, self::TAG_LENGTH);
        $offset += self::TAG_LENGTH;

        $ciphertext = substr($binary, $offset);

        $payload = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $this->getEncryptionKey(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
        );

        if ($payload === false) {
            throw new RuntimeException(
                'Unable to decrypt search state or authentication failed.'
            );
        }

        $state = json_decode(
            $payload,
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        if (!is_array($state)) {
            throw new RuntimeException('Invalid search state payload.');
        }

        return $state;
    }

    /**
     * @return string
     */
    private function getEncryptionKey(): string
    {
        $encryptionKey = (string)(
            $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] ?? ''
        );

        if ($encryptionKey === '') {
            throw new RuntimeException(
                'TYPO3 SYS/encryptionKey is not configured.'
            );
        }

        return hash_hmac(
            'sha256',
            self::KEY_CONTEXT,
            $encryptionKey,
            true
        );
    }

    /**
     * @param string $value
     * @return string
     */
    private function base64UrlEncode(string $value): string
    {
        return rtrim(
            strtr(base64_encode($value), '+/', '-_'),
            '='
        );
    }

    /**
     * @param string $value
     * @return string
     */
    private function base64UrlDecode(string $value): string
    {
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $value)) {
            throw new RuntimeException('Invalid Base64URL search state.');
        }

        $padding = strlen($value) % 4;

        if ($padding !== 0) {
            $value .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode(
            strtr($value, '-_', '+/'),
            true
        );

        if ($decoded === false) {
            throw new RuntimeException('Invalid Base64URL search state.');
        }

        return $decoded;
    }
}
