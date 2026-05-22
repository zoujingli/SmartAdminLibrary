<?php

declare(strict_types=1);
/**
 * This file is part of SmartAdmin.
 *
 * @contact Anyon <zoujingli@qq.com>
 * @license https://github.com/zoujingli/SmartAdmin/blob/master/LICENSE
 * @document https://github.com/zoujingli/SmartAdmin/blob/master/readme.md
 */

namespace Library\Auth\Constant;

use Lcobucci\JWT\Builder;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer;
use Lcobucci\JWT\Signer\Key;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\Validation\Constraint\IdentifiedBy;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Library\Auth\Exception\TokenExpireException;

/**
 * JWT 工具类.
 */
final class JwtConstant
{
    /**
     * 获取配置对象.
     */
    public static function getConfiguration(Signer $signer, Key $key): Configuration
    {
        return Configuration::forSymmetricSigner($signer, $key);
    }

    /**
     * 获取 Token 构建器.
     */
    public static function getBuilder(Signer $signer, Key $key): Builder
    {
        return self::getConfiguration($signer, $key)->builder();
    }

    /**
     * 获取 Token 解析器.
     */
    public static function getParser(Signer $signer, Key $key): Parser
    {
        return self::getConfiguration($signer, $key)->parser();
    }

    /**
     * 验证 Token 有效性.
     *
     * @throws TokenExpireException
     */
    public static function getValidationData(Signer $signer, Key $key, string $token): bool
    {
        $config = self::getConfiguration($signer, $key);
        $parser = $config->parser()->parse($token);
        $claims = $parser->claims()->all();

        $now = new \DateTimeImmutable();

        // 基于 claims 的过期时间判断
        if (($claims['nbf'] ?? $now) > $now || ($claims['exp'] ?? $now) < $now) {
            throw new TokenExpireException('Token has expired');
        }

        // 添加校验约束（一次性传入，避免覆盖）
        $config->setValidationConstraints(
            new IdentifiedBy($claims['jti'] ?? ''),
            new SignedWith($signer, $key)
        );

        return $config->validator()->validate($parser, ...$config->validationConstraints());
    }
}
