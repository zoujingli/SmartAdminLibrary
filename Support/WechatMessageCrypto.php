<?php

declare(strict_types=1);
/**
 * This file is part of SmartAdmin.
 *
 * @contact Anyon <zoujingli@qq.com>
 * @license https://github.com/zoujingli/SmartAdmin/blob/master/LICENSE
 * @document https://zoujingli.github.io/SmartAdmin
 */

namespace Library\Support;

use Library\Exception\ErrorResponseException;

/**
 * 微信消息入站协议：只负责 XML、SHA1 和 AES-CBC，不访问账号、租户或业务数据。
 *
 * SDK 已移除此能力；公众号与开放平台独立安装时共用此无 SDK 依赖的协议实现，避免互相依赖业务插件。
 */
final class WechatMessageCrypto
{
    private readonly string $aesKey;

    public function __construct(
        private readonly string $token,
        string $encodingAesKey,
        private readonly string $appid,
    ) {
        $key = base64_decode($encodingAesKey . '=', true);
        if ($token === '' || $appid === '' || strlen($encodingAesKey) !== 43 || !is_string($key) || strlen($key) !== 32) {
            throw new ErrorResponseException('微信消息加解密配置无效');
        }
        $this->aesKey = $key;
    }

    /** 校验消息签名；不在异常中泄漏 Token、密文或计算结果。 */
    public static function assertSignature(string $signature, array $items): void
    {
        if ($signature === '' || in_array('', $items, true)) {
            throw new ErrorResponseException('微信回调签名参数不完整');
        }
        sort($items, SORT_STRING);
        if (!hash_equals(sha1(implode('', $items)), $signature)) {
            throw new ErrorResponseException('微信回调签名验证失败');
        }
    }

    /** 验签先于解密；长度、完整填充和接收方 AppID 都通过后才返回业务消息。 */
    public function decryptMessage(string $xml, string $signature, string $timestamp, string $nonce): array
    {
        $payload = self::decodeXml($xml);
        $encrypted = $payload['Encrypt'] ?? null;
        if (!is_string($encrypted) || $encrypted === '') {
            throw new ErrorResponseException('微信加密消息缺少 Encrypt 字段');
        }
        self::assertSignature($signature, [$this->token, $timestamp, $nonce, $encrypted]);
        $cipher = base64_decode($encrypted, true);
        if (!is_string($cipher) || $cipher === '' || strlen($cipher) % 16 !== 0) {
            throw new ErrorResponseException('微信加密消息密文无效');
        }
        $plain = openssl_decrypt($cipher, 'AES-256-CBC', $this->aesKey, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, substr($this->aesKey, 0, 16));
        if (!is_string($plain) || $plain === '') {
            throw new ErrorResponseException('微信加密消息解密失败');
        }
        // 微信采用 32 字节 PKCS#7 分组，不能只移除末尾字节而不校验完整填充。
        $padding = ord(substr($plain, -1));
        if ($padding < 1 || $padding > 32 || substr($plain, -$padding) !== str_repeat(chr($padding), $padding)) {
            throw new ErrorResponseException('微信加密消息填充无效');
        }
        $plain = substr($plain, 0, -$padding);
        if (strlen($plain) < 20) {
            throw new ErrorResponseException('微信加密消息长度无效');
        }
        $length = unpack('Nlength', substr($plain, 16, 4))['length'];
        if ($length <= 0 || strlen($plain) <= 20 + $length || !hash_equals($this->appid, substr($plain, 20 + $length))) {
            throw new ErrorResponseException('微信加密消息长度或 AppID 不匹配');
        }

        return self::decodeXml(substr($plain, 20, $length));
    }

    /** 加密被动回复，输出微信约定的四个 XML 字段；无需出站 API 的 AppSecret。 */
    public function encryptMessage(string $xml, string $timestamp, string $nonce): string
    {
        $nonce = $nonce === '' ? bin2hex(random_bytes(16)) : $nonce;
        $plain = random_bytes(16) . pack('N', strlen($xml)) . $xml . $this->appid;
        $padding = 32 - strlen($plain) % 32;
        $cipher = openssl_encrypt($plain . str_repeat(chr($padding), $padding), 'AES-256-CBC', $this->aesKey, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, substr($this->aesKey, 0, 16));
        if (!is_string($cipher)) {
            throw new ErrorResponseException('微信加密消息加密失败');
        }
        $encrypted = base64_encode($cipher);
        $items = [$this->token, $timestamp, $nonce, $encrypted];
        sort($items, SORT_STRING);
        $fields = ['Encrypt' => $encrypted, 'MsgSignature' => sha1(implode('', $items)), 'TimeStamp' => $timestamp, 'Nonce' => $nonce];
        $result = '<xml>';
        foreach ($fields as $name => $value) {
            $result .= '<' . $name . '>' . htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</' . $name . '>';
        }

        return $result . '</xml>';
    }

    /** 只解析有限大小的消息 XML，拒绝 DTD/实体，保留 CDATA 和重复节点。 */
    public static function decodeXml(string $xml): array
    {
        if ($xml === '' || strlen($xml) > 2 * 1024 * 1024 || str_contains($xml, "\0")) {
            throw new ErrorResponseException('微信 XML 格式无效');
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $element = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NOCDATA | LIBXML_NONET);
            if (!$element instanceof \SimpleXMLElement || $element->getName() !== 'xml') {
                throw new ErrorResponseException('微信 XML 格式无效');
            }
            // 不启用实体替换、DTD 加载或校验；检查解析树中的真实 DTD，允许 CDATA/注释里的声明样式普通文本。
            // DOM 与 SimpleXML 共享同一解析树，不重新解析，也不读取外部实体内容。
            $document = dom_import_simplexml($element)->ownerDocument;
            if (!$document instanceof \DOMDocument || $document->doctype !== null) {
                throw new ErrorResponseException('微信 XML 不允许 DTD 或实体声明');
            }

            return self::xmlChildren($element);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private static function xmlChildren(\SimpleXMLElement $element): array
    {
        $result = [];
        foreach ($element->children() as $name => $child) {
            $value = $child->children()->count() > 0 ? self::xmlChildren($child) : (string)$child;
            if (array_key_exists($name, $result)) {
                if (!is_array($result[$name]) || !array_is_list($result[$name])) {
                    $result[$name] = [$result[$name]];
                }
                $result[$name][] = $value;
            } else {
                $result[$name] = $value;
            }
        }

        return $result;
    }
}
