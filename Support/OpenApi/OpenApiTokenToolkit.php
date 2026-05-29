<?php

declare(strict_types=1);
/**
 * This file is part of SmartAdmin.
 *
 * @contact Anyon <zoujingli@qq.com>
 * @license https://github.com/zoujingli/SmartAdmin/blob/master/LICENSE
 * @document https://zoujingli.github.io/SmartAdmin
 */

namespace Library\Support\OpenApi;

use Hyperf\Context\Context;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\Redis\RedisFactory;
use Library\Auth\Constant\JwtAbstract;
use Library\Auth\Token;
use Library\Exception\NotAllowResponseException;
use Library\Exception\UnauthorizedResponseException;

/**
 * 开放接口 Token 与请求守卫公共工具。
 *
 * 这里集中处理认证参数、nonce、JWT scene 和通用调用策略；插件只负责加载应用、解密密钥、
 * 构建 claims 与校验业务资源边界，避免 Website 与 Material 维护两套相似但不一致的逻辑。
 */
final class OpenApiTokenToolkit
{
    public const AUTH_SIGN_TTL = 300;

    public const REFRESH_TOKEN_TTL = 30 * 24 * 3600;

    public const MIN_ACCESS_TOKEN_TTL = 300;

    public const MAX_ACCESS_TOKEN_TTL = 86400;

    /**
     * @param array<string, mixed> $payload
     * @return array{appid:string,timestamp:int,nonce:string,sign:string}
     */
    public static function parseSignaturePayload(array $payload): array
    {
        $appid = strtolower(trim((string)($payload['appid'] ?? '')));
        $timestampText = trim((string)($payload['timestamp'] ?? ''));
        $nonce = trim((string)($payload['nonce'] ?? ''));
        $sign = strtolower(trim((string)($payload['sign'] ?? '')));

        if ($appid === '' || $timestampText === '' || $nonce === '' || $sign === '') {
            throw new UnauthorizedResponseException('开放接口认证参数缺失');
        }
        if (!ctype_digit($timestampText)) {
            throw new UnauthorizedResponseException('timestamp 必须是整数');
        }

        $timestamp = (int)$timestampText;
        if ($timestamp < 1 || abs(time() - $timestamp) > self::AUTH_SIGN_TTL) {
            throw new UnauthorizedResponseException('timestamp 超出允许范围');
        }
        if (preg_match('/^[A-Za-z0-9_-]{16,64}$/', $nonce) !== 1) {
            throw new UnauthorizedResponseException('nonce 格式不正确');
        }
        if (preg_match('/^[0-9a-f]{64}$/', $sign) !== 1) {
            throw new UnauthorizedResponseException('sign 格式不正确');
        }

        return compact('appid', 'timestamp', 'nonce', 'sign');
    }

    public static function assertSignature(string $appid, string $appkey, string $nonce, int $timestamp, string $sign): void
    {
        if ($appkey === '') {
            throw new NotAllowResponseException('开放接口密钥不可用，请在后台重置密钥');
        }

        $expected = OpenApiSignature::tokenSign($appid, $appkey, $nonce, $timestamp);
        if (!hash_equals($expected, $sign)) {
            throw new UnauthorizedResponseException('sign 校验失败');
        }
    }

    public static function assertNonceUnused(string $namespace, string $appid, string $nonce): void
    {
        $key = sprintf('%s:openapi:auth:nonce:%s:%s', trim($namespace, ':'), $appid, hash('sha256', $nonce));
        try {
            $redis = make(RedisFactory::class)->get('default');
            $ok = (bool)$redis->set($key, '1', ['NX', 'EX' => self::AUTH_SIGN_TTL]);
        } catch (\Throwable) {
            $ok = _cache($key) === null;
            $ok && _cache($key, 1, self::AUTH_SIGN_TTL);
        }

        if (!$ok) {
            throw new UnauthorizedResponseException('nonce 已使用，请更换后重试');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public static function bearerClaims(RequestInterface $request, string $scene): array
    {
        $authorization = trim($request->getHeaderLine('Authorization'));
        if (!preg_match('/^Bearer\s+(.+)$/i', $authorization, $match)) {
            throw new UnauthorizedResponseException('access_token 缺失');
        }

        $previousScene = self::currentTokenScene();
        try {
            $token = make(Token::class)->setScene($scene);
            $accessToken = trim($match[1]);
            $token->check($accessToken, $scene);
            $claims = $token->getParserData($accessToken);
        } catch (\Throwable) {
            throw new UnauthorizedResponseException('access_token 无效或已过期');
        } finally {
            self::restoreTokenScene($previousScene);
        }

        return is_array($claims) ? $claims : [];
    }

    /**
     * @return array<string, mixed>
     */
    public static function refreshClaims(string $refreshToken, string $scene): array
    {
        $refreshToken = trim($refreshToken);
        if ($refreshToken === '') {
            throw new UnauthorizedResponseException('refresh_token 不能为空');
        }

        $previousScene = self::currentTokenScene();
        try {
            $token = make(Token::class)->setScene($scene);
            $token->check($refreshToken, $scene);
            $claims = $token->getParserData($refreshToken);
        } catch (\Throwable) {
            throw new UnauthorizedResponseException('refresh_token 无效或已过期');
        } finally {
            self::restoreTokenScene($previousScene);
        }

        return is_array($claims) ? $claims : [];
    }

    /**
     * @param array<string, mixed> $accessClaims
     * @param array<string, mixed> $refreshClaims
     * @return array<string, int|string>
     */
    public static function issueTokens(string $accessScene, string $refreshScene, array $accessClaims, array $refreshClaims, int $accessTtl): array
    {
        $accessTtl = self::normalizeAccessTokenTtl($accessTtl);
        $previousScene = self::currentTokenScene();

        try {
            $accessToken = (string)make(Token::class)
                ->setScene($accessScene)
                ->create($accessClaims, false);
            $refreshToken = (string)make(Token::class)
                ->setScene($refreshScene)
                ->create($refreshClaims, false);
        } finally {
            self::restoreTokenScene($previousScene);
        }

        return [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'token_type' => 'Bearer',
            'expires_in' => $accessTtl,
            'refresh_expires_in' => self::REFRESH_TOKEN_TTL,
        ];
    }

    public static function normalizeAccessTokenTtl(int $ttl): int
    {
        return max(self::MIN_ACCESS_TOKEN_TTL, min(self::MAX_ACCESS_TOKEN_TTL, $ttl));
    }

    public static function generateRefreshNonce(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * @param array<int, string> $whitelist
     */
    public static function isIpAllowed(string $ip, array $whitelist): bool
    {
        $ip = trim($ip);
        if ($whitelist === [] || in_array('*', $whitelist, true)) {
            return true;
        }
        if ($ip === '') {
            return false;
        }

        foreach ($whitelist as $rule) {
            $rule = trim((string)$rule);
            if ($rule === $ip || self::ipv4CidrContains($ip, $rule)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, string> $whitelist
     * @return array<int, string>
     */
    public static function normalizeIpWhitelist(array $whitelist): array
    {
        $items = [];
        foreach ($whitelist as $item) {
            $item = trim((string)$item);
            if ($item === '') {
                continue;
            }
            if ($item === '*' || filter_var($item, FILTER_VALIDATE_IP)) {
                $items[$item] = $item;
                continue;
            }
            if (preg_match('/^([0-9]{1,3}(?:\.[0-9]{1,3}){3})\/(\d{1,2})$/', $item, $matches) === 1
                && filter_var($matches[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
                && (int)$matches[2] >= 0
                && (int)$matches[2] <= 32) {
                $items[$item] = $item;
                continue;
            }

            throw new NotAllowResponseException(sprintf('IP 白名单格式错误：%s', $item));
        }

        return array_values($items);
    }

    public static function assertRateLimit(string $namespace, string $appid, int $limit): void
    {
        if ($limit <= 0) {
            return;
        }

        $key = sprintf('%s:openapi:rate:%s:%s', trim($namespace, ':'), $appid, date('YmdHi'));
        try {
            // Redis 可用时用 INCR 保证同一分钟窗口的并发计数原子；本地无 Redis 时再退回通用缓存。
            $redis = make(RedisFactory::class)->get('default');
            $count = (int)$redis->incr($key);
            if ($count === 1) {
                $redis->expire($key, 70);
            }
        } catch (\Throwable) {
            $count = (int)(_cache($key) ?? 0) + 1;
            _cache($key, $count, 70);
        }
        if ($count > $limit) {
            throw new NotAllowResponseException('开放接口调用过于频繁');
        }
    }

    private static function ipv4CidrContains(string $ip, string $rule): bool
    {
        if (preg_match('/^([0-9]{1,3}(?:\.[0-9]{1,3}){3})\/(\d{1,2})$/', $rule, $matches) !== 1) {
            return false;
        }
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || !filter_var($matches[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }

        $prefix = (int)$matches[2];
        if ($prefix < 0 || $prefix > 32) {
            return false;
        }
        $mask = $prefix === 0 ? 0 : (-1 << (32 - $prefix));

        return ((int)ip2long($ip) & $mask) === ((int)ip2long($matches[1]) & $mask);
    }

    private static function currentTokenScene(): mixed
    {
        $ref = new \ReflectionClass(JwtAbstract::class);
        $constant = $ref->getConstant('CONTEXT_SCENE_KEY');

        return is_string($constant) ? Context::get($constant) : null;
    }

    private static function restoreTokenScene(mixed $scene): void
    {
        $ref = new \ReflectionClass(JwtAbstract::class);
        $constant = $ref->getConstant('CONTEXT_SCENE_KEY');
        if (is_string($constant)) {
            Context::set($constant, $scene);
        }
    }
}
