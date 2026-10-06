<?php

declare(strict_types=1);
/**
 * This file is part of SmartAdmin.
 *
 * @contact Anyon <zoujingli@qq.com>
 * @license https://github.com/zoujingli/SmartAdmin/blob/master/LICENSE
 * @document https://zoujingli.github.io/SmartAdmin
 */

namespace Tests\Unit\Library\Support;

use Library\Exception\ErrorResponseException;
use Library\Support\WechatMessageCrypto;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** @internal */
#[CoversClass(WechatMessageCrypto::class)]
final class WechatMessageCryptoTest extends TestCase
{
    private const KEY = 'abcdefghijklmnopqrstuvwxyz012345';

    public function testDecodesIndependentProtocolFixture(): void
    {
        // 用协议字节独立构造入站夹具，避免加解密实现同时出错却通过往返测试。
        $xml = '<xml><MsgType><![CDATA[text]]></MsgType><Content><![CDATA[测试<&]]></Content></xml>';
        $plain = str_repeat('r', 16) . pack('N', strlen($xml)) . $xml . 'wx-fixture';
        $pad = 32 - strlen($plain) % 32;
        $encrypted = base64_encode(openssl_encrypt($plain . str_repeat(chr($pad), $pad), 'AES-256-CBC', self::KEY, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, substr(self::KEY, 0, 16)));
        $parts = ['callback-token', '12345', 'nonce', $encrypted];
        sort($parts, SORT_STRING);
        $crypto = new WechatMessageCrypto('callback-token', rtrim(base64_encode(self::KEY), '='), 'wx-fixture');

        self::assertSame(['MsgType' => 'text', 'Content' => '测试<&'], $crypto->decryptMessage('<xml><Encrypt>' . $encrypted . '</Encrypt></xml>', sha1(implode('', $parts)), '12345', 'nonce'));
    }

    public function testReplyRoundTripPreservesXmlCharacters(): void
    {
        $crypto = new WechatMessageCrypto('token', rtrim(base64_encode(self::KEY), '='), 'wx-fixture');
        $reply = WechatMessageCrypto::decodeXml($crypto->encryptMessage('<xml><Content><![CDATA[回复<&]]></Content></xml>', '12345', 'n<&'));
        self::assertSame('n<&', $reply['Nonce']);
        self::assertSame(['Content' => '回复<&'], $crypto->decryptMessage('<xml><Encrypt>' . $reply['Encrypt'] . '</Encrypt></xml>', $reply['MsgSignature'], $reply['TimeStamp'], $reply['Nonce']));
    }

    public function testCdataDeclarationTextSurvivesSignedCallback(): void
    {
        $text = '<!DOCTYPE html> <!ENTITY example "text">';
        $xml = '<xml><!-- <!DOCTYPE example> --><Content><![CDATA[' . $text . ']]></Content></xml>';
        self::assertSame(['Content' => $text], WechatMessageCrypto::decodeXml($xml));
        $crypto = new WechatMessageCrypto('token', rtrim(base64_encode(self::KEY), '='), 'wx-fixture');
        $reply = $crypto->encryptMessage($xml, '12345', 'nonce');
        $fields = WechatMessageCrypto::decodeXml($reply);
        self::assertSame(['Content' => $text], $crypto->decryptMessage($reply, $fields['MsgSignature'], '12345', 'nonce'));
    }

    public function testComposerDeclaresCallbackCapabilityAndXmlExtensions(): void
    {
        // 由类文件定位包根目录，确保该合同同样在独立 Library 导出包运行。
        $path = dirname((new \ReflectionClass(WechatMessageCrypto::class))->getFileName(), 2) . '/composer.json';
        self::assertFileExists($path);
        $composer = json_decode((string)file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('*', $composer['require']['ext-simplexml'] ?? null);
        self::assertSame('*', $composer['require']['ext-dom'] ?? null);
        self::assertSame('1.0.0', $composer['provide']['zoujingli/smart-admin-wechat-message-crypto'] ?? null);
    }

    public function testRejectsDtdWithoutLoadingExternalEntities(): void
    {
        $loads = 0;
        $loader = libxml_get_external_entity_loader();
        libxml_set_external_entity_loader(static function () use (&$loads) {
            ++$loads;
            return null;
        });
        try {
            try {
                WechatMessageCrypto::decodeXml('<!DOCTYPE xml [<!ENTITY x SYSTEM "file:///fixture-only-no-read">]><xml><Content>&x;</Content></xml>');
                self::fail('真实 DTD 必须拒绝');
            } catch (ErrorResponseException $exception) {
                self::assertStringContainsString('DTD', $exception->getMessage());
                self::assertSame(0, $loads, '拒绝 DTD 前不得加载任何外部实体');
            }
        } finally {
            libxml_set_external_entity_loader($loader);
        }
    }

    public function testRejectsWrongReceiverAfterValidSignature(): void
    {
        $sender = new WechatMessageCrypto('token', rtrim(base64_encode(self::KEY), '='), 'wx-other');
        $reply = WechatMessageCrypto::decodeXml($sender->encryptMessage('<xml><Content>demo</Content></xml>', '12345', 'nonce'));
        $receiver = new WechatMessageCrypto('token', rtrim(base64_encode(self::KEY), '='), 'wx-fixture');
        $this->expectException(ErrorResponseException::class);
        $this->expectExceptionMessage('AppID');
        $receiver->decryptMessage('<xml><Encrypt>' . $reply['Encrypt'] . '</Encrypt></xml>', $reply['MsgSignature'], $reply['TimeStamp'], $reply['Nonce']);
    }

    #[DataProvider('invalidXml')]
    public function testRejectsUnsafeXml(string $xml): void
    {
        $this->expectException(ErrorResponseException::class);
        WechatMessageCrypto::decodeXml($xml);
    }

    public static function invalidXml(): array
    {
        return [
            [''], ['<xml>'], ['<other/>'],
            ['<!DOCTYPE xml [<!ENTITY x SYSTEM "file:///etc/passwd">]><xml><Content>&x;</Content></xml>'],
            ['<!DOCTYPE xml><xml><Content>hello</Content></xml>'],
            ['<!DOCTYPE xml [<!ENTITY x "hello">]><xml><Content>&x;</Content></xml>'],
            ['<!DOCTYPE xml SYSTEM "https://example.invalid/fixture.dtd"><xml/>'],
        ];
    }

    public function testSignatureRejectsIncompleteArguments(): void
    {
        $this->expectException(ErrorResponseException::class);
        WechatMessageCrypto::assertSignature(sha1('token'), ['token', '', '']);
    }

    public function testSignatureRejectsTampering(): void
    {
        $this->expectException(ErrorResponseException::class);
        WechatMessageCrypto::assertSignature(str_repeat('a', 40), ['token', '12345', 'nonce']);
    }
}
