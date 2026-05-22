<?php

declare(strict_types=1);
/**
 * This file is part of SmartAdmin.
 *
 * @contact Anyon <zoujingli@qq.com>
 * @license https://github.com/zoujingli/SmartAdmin/blob/master/LICENSE
 * @document https://github.com/zoujingli/SmartAdmin/blob/master/readme.md
 */

namespace Library\Auth;

use Lcobucci\JWT\Token\Plain;
use Lcobucci\JWT\Token\RegisteredClaims;
use Library\Auth\Constant\JwtAbstract;
use Library\Auth\Constant\JwtTimeUtil;

/**
 * 黑名单管理.
 */
class Black extends JwtAbstract
{
    /**
     * 把 token 加入到黑名单中.
     */
    public function addTokenBlack(Plain $token, string $scene = 'default', array $config = []): bool
    {
        if (!$this->getConfig('blacklist_enabled', false, $scene, $config)) {
            return false;
        }

        $claims = $token->claims();
        $expTime = $claims->get(RegisteredClaims::EXPIRATION_TIME);
        $expTime = is_numeric($expTime) ? (int)$expTime : $expTime->getTimestamp();
        $cacheKey = "{$this->getBlackPrefix()}_{$claims->get('jti')}";
        $validUntil = JwtTimeUtil::now()->getTimestamp();

        // 缓存时长 = token 过期时间 - 当前时间
        $tokenCacheTime = JwtTimeUtil::timestamp($expTime)->max(JwtTimeUtil::now())->diffInSeconds();
        return $this->cache->set($cacheKey, ['valid_until' => $validUntil], $tokenCacheTime);
    }

    /**
     * 判断 token 是否已经在黑名单.
     */
    public function hasTokenBlack(mixed $claims, string $scene = 'default', array $config = []): bool
    {
        if (!$this->getConfig('blacklist_enabled', false, $scene, $config)) {
            return false;
        }

        $val = $this->cache->get("{$this->getBlackPrefix()}_{$claims['jti']}");
        if (empty($val['valid_until'])) {
            return false;
        }

        return match ($this->getConfig('type', 'mpop', $scene, $config)) {
            'mpop' => !JwtTimeUtil::isFuture($val['valid_until']),
            'sso' => !self::checkSso($claims['iat'] ?? null, $val['valid_until']),
            default => false,
        };
    }

    /**
     * 黑名单移除指定 token.
     */
    public function remove(string $key): bool
    {
        return $this->cache->delete($key);
    }

    /**
     * 清空黑名单缓存.
     */
    public function clear(): bool
    {
        return $this->cache->delete($this->getBlackPrefix() . '_*');
    }

    /**
     * 获取黑名单 TTL.
     */
    public function getCacheTTL(): int
    {
        return $this->getConfig('ttl', 3600);
    }

    /**
     * 获取黑名单缓存前缀.
     */
    public function getBlackPrefix(): string
    {
        return $this->getConfig('blacklist_prefix', 'jwt_black');
    }

    /**
     * 校验 sso 模式.
     */
    private static function checkSso(mixed $issuedAt, int|string $validUntil): bool
    {
        return match (true) {
            empty($issuedAt) => false,
            default => (is_numeric($issuedAt) ? (int)$issuedAt : $issuedAt->getTimestamp()) - (int)$validUntil >= 0,
        };
    }
}
