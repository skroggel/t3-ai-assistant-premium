<?php
declare(strict_types=1);

namespace Madj2k\AiAssistantPremium\Tests\Unit\Security;

use Madj2k\AiAssistantPremium\Security\FrontendRequestTokenService;
use PHPUnit\Framework\TestCase;

final class FrontendRequestTokenServiceTest extends TestCase
{
    private const ENCRYPTION_KEY = 'frontend-token-test-key';

    private FrontendRequestTokenService $subject;

    protected function setUp(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = self::ENCRYPTION_KEY;
        $this->subject = new FrontendRequestTokenService();
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey']);
    }

    public function testAcceptsTokenWithMatchingContext(): void
    {
        $context = [
            'pageUid' => 163,
            'assistantProfile' => 1001,
            'chatIdentifier' => 'chat-123',
        ];

        $token = $this->subject->create($context, 3600);

        self::assertTrue($this->subject->isValid($token, $context));
    }

    public function testRejectsTokenWithDifferentContext(): void
    {
        $token = $this->subject->create([
            'pageUid' => 163,
            'assistantProfile' => 1001,
            'chatIdentifier' => 'chat-123',
        ], 3600);

        self::assertFalse($this->subject->isValid($token, [
            'pageUid' => 163,
            'assistantProfile' => 1002,
            'chatIdentifier' => 'chat-123',
        ]));
    }

    public function testRejectsTamperedPayload(): void
    {
        $token = $this->subject->create([
            'pageUid' => 163,
            'assistantProfile' => 1001,
        ], 3600);
        [$payload, $signature] = explode('.', $token, 2);
        $tamperedPayload = rtrim(strtr(base64_encode('{"pageUid":999}'), '+/', '-_'), '=');

        self::assertFalse($this->subject->isValid(
            $tamperedPayload . '.' . $signature,
            ['pageUid' => 999, 'assistantProfile' => 1001],
        ));
    }

    public function testRejectsExpiredToken(): void
    {
        $token = $this->subject->create([
            'pageUid' => 163,
            'assistantProfile' => 1001,
        ], 60);
        [$payload, $signature] = explode('.', $token, 2);
        $claims = json_decode($this->decode($payload), true, 512, JSON_THROW_ON_ERROR);
        $claims['exp'] = time() - 1;
        $expiredPayload = $this->encode(json_encode($claims, JSON_THROW_ON_ERROR));
        $expiredSignature = $this->sign($expiredPayload);

        self::assertFalse($this->subject->isValid(
            $expiredPayload . '.' . $expiredSignature,
            ['pageUid' => 163, 'assistantProfile' => 1001],
        ));
    }

    public function testRejectsMalformedToken(): void
    {
        self::assertFalse($this->subject->isValid('not-a-token', ['pageUid' => 163]));
    }

    private function sign(string $payload): string
    {
        return $this->encode(hash_hmac(
            'sha256',
            'madj2k/ai-assistant-premium/frontend-request/v1.' . $payload,
            self::ENCRYPTION_KEY,
            true,
        ));
    }

    private function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function decode(string $value): string
    {
        $padding = strlen($value) % 4;
        if ($padding !== 0) {
            $value .= str_repeat('=', 4 - $padding);
        }

        return (string)base64_decode(strtr($value, '-_', '+/'), true);
    }
}
