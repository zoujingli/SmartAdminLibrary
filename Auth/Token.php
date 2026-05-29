<?php

declare(strict_types=1);
/**
 * This file is part of SmartAdmin.
 *
 * @contact Anyon <zoujingli@qq.com>
 * @license https://github.com/zoujingli/SmartAdmin/blob/master/LICENSE
 * @document https://zoujingli.github.io/SmartAdmin
 */

namespace Library\Auth;

use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Token as JwtToken;
use Lcobucci\JWT\Token\RegisteredClaims;
use Lcobucci\JWT\UnencryptedToken;
use Library\Auth\Constant\JwtAbstract;
use Library\Auth\Constant\JwtConstant;
use Library\Auth\Constant\JwtTimeUtil;
use Library\Auth\Exception\JwtException;
use Library\Auth\Exception\TokenValidException;
use Library\Helper\RequestHelper;
use Psr\SimpleCache\InvalidArgumentException;

/**
 * JWT Token 管理服务
 * 负责Token生成、验证、解析，支持SSO/MPOP多场景.
 */
class Token extends JwtAbstract
{
    /**
     * 生成JWT Token
     * SystemUser->SSO登录，其他->MPOP登录.
     */
    public function create(array $claims, bool $isInsertSsoBlack = true, ?int $ttl = null): JwtToken
    {
        if (!$this->getConfig('alg')) {
            throw new JwtException("The jwt scene [{$this->getScene()}] not found", 400);
        }

        $date = new \DateTimeImmutable();
        // 开放接口需要按应用配置覆盖本次签发 TTL，不能临时改写全局 scene 配置，否则 Swoole 并发下会串扰其它请求。
        $ttl ??= (int)$this->getConfig('ttl');
        $signer = new ($this->getConfig('supported_algs')[$this->getConfig('alg')])();
        $builder = JwtConstant::getBuilder($signer, $this->getKey())
            ->identifiedBy(match ($this->getConfig('type')) {
                'mpop' => uniqid($this->getScene() . '_', true),
                'sso' => $this->getScene() . '_' . ($claims['uid'] ?? throw new JwtException('There is no uid key in the claims', 400)),
                default => uniqid($this->getScene() . '_', true)
            })
            ->issuedAt($date)
            ->canOnlyBeUsedAfter($date)
            ->expiresAt($date->modify(sprintf('+%s second', $ttl)));

        // 写入场景信息和用户自定义 claims
        $claims[$this->tokenScenePrefix] = $this->getScene();
        foreach ($claims as $key => $value) {
            $builder = $builder->withClaim($key, $value);
        }

        $token = $builder->getToken($signer, $this->getKey());

        // SSO 模式下需要把旧 token 加入黑名单
        if ($this->getConfig('type') === 'sso' && $isInsertSsoBlack) {
            _once(Black::class)->addTokenBlack($token, $this->getScene(), (array)$this->getSceneConfig($this->getScene()));
        }

        return $token;
    }

    /**
     * 验证 Token.
     * @throws InvalidArgumentException|\Throwable
     */
    public function check(?string $token = null, ?string $scene = null, bool $validate = true): bool
    {
        $token = $token ?? $this->getHeaderToken();

        // 如果指定了场景，需要验证token中的场景信息
        if (!is_null($scene)) {
            $tokenObject = $this->getTokenObject($token);
            $claims = $tokenObject->claims()->all();
            $tokenScene = $claims['jwt_scene'] ?? 'default';
            if ($tokenScene !== $scene) {
                throw new TokenValidException('Token scene mismatch', 401);
            }
        }

        if ($this->getConfig('blacklist_enabled', false, $scene)) {
            $tokenObject = $this->getTokenObject($token);
            if (_once(Black::class)->hasTokenBlack($tokenObject->claims()->all(), $scene ?? $this->getScene(), (array)$this->getSceneConfig($scene ?? $this->getScene()))) {
                throw new TokenValidException('Token authentication does not pass', 401);
            }
        }
        if ($validate) {
            $alg = $this->getConfig('supported_algs', [], $scene)[$this->getConfig('alg', null, $scene)];
            if (!JwtConstant::getValidationData(new $alg(), $this->getKey('public'), $token)) {
                throw new TokenValidException('Token authentication does not pass', 401);
            }
        }
        return true;
    }

    /**
     * 刷新 Token.
     */
    public function refresh(?string $token = null): JwtToken|string
    {
        $claims = $this->getParserData($token ?? $this->getHeaderToken());
        unset($claims['iat'], $claims['nbf'], $claims['exp'], $claims['jti']);
        return $this->create($claims);
    }

    /**
     * 让 Token 失效（加入黑名单）.
     */
    public function logout(?string $token = null, ?string $scene = null): bool
    {
        $tokenObject = $this->getTokenObject($token);
        $scene ??= (string)($tokenObject->claims()->all()[$this->tokenScenePrefix] ?? $this->getScene());

        _once(Black::class)->addTokenBlack($tokenObject, $scene, (array)$this->getSceneConfig($scene));

        return true;
    }

    /**
     * 获取 Token 动态剩余有效时间.
     */
    public function getTokenDynamicCacheTime(?string $token = null): int
    {
        $tokenObject = $this->getTokenObject($token ?: $this->getHeaderToken());
        $claims = $tokenObject->claims();
        return $claims->has(RegisteredClaims::EXPIRATION_TIME) ? JwtTimeUtil::timestamp($claims->get(RegisteredClaims::EXPIRATION_TIME))->max(JwtTimeUtil::now())->diffInSeconds() : -1;
    }

    /**
     * 获取 Token 解析后的 claims 数据.
     */
    public function getParserData(?string $token = null): array
    {
        $token = $token ?? $this->getHeaderToken();
        $tokenObject = $this->getTokenObject($token);
        $scene = (string)($tokenObject->claims()->all()[$this->tokenScenePrefix] ?? $this->getScene());

        $this->check($token, $scene);

        return $tokenObject->claims()->all();
    }

    /**
     * 获取 Token TTL（单位秒）.
     */
    public function getTTL(?string $scene = null): int
    {
        return (int)$this->getConfig('ttl', 0, $scene);
    }

    /**
     * 从请求头中获取 Token.
     */
    public function getHeaderToken(): string
    {
        // 请求对象统一由 RequestHelper 解析，避免 Token 自行读取 Context/容器导致协程上下文规则分叉。
        $request = RequestHelper::getRequest();
        $token = '';
        if ($request !== null) {
            $token = trim((string)$request->getHeaderLine('Authorization'));
            if ($token === '') {
                $token = trim((string)$request->getHeaderLine('token'));
            }
        }
        if ($token === '') {
            return '';
        }

        // 兼容 Bearer 大小写、前后空格。
        $pattern = '/^' . preg_quote($this->tokenPrefix, '/') . '\s+/i';
        return preg_replace($pattern, '', trim($token), 1) ?: '';
    }

    /**
     * 根据配置获取对应算法所需的 Key.
     * @param string $type 取值 private|public
     */
    private function getKey(string $type = 'private'): ?InMemory
    {
        return match (true) {
            in_array($this->getConfig('alg'), $this->getConfig('symmetry_algs', []), true) => InMemory::base64Encoded($this->getConfig('secret')),
            in_array($this->getConfig('alg'), $this->getConfig('asymmetric_algs', []), true) => InMemory::base64Encoded($this->getConfig('keys')[$type]),
            default => null,
        };
    }

    /**
     * 解析 Token 对象.
     */
    private function getTokenObject(?string $token = null): UnencryptedToken
    {
        if (empty($token ??= $this->getHeaderToken())) {
            throw new JwtException('A token is required', 400);
        }

        // 先解析 token 获取场景信息（不验证签名）
        try {
            $parts = explode('.', $token);
            if (count($parts) !== 3) {
                throw new JwtException('Invalid token format', 400);
            }

            $decoded = $this->decodeBase64UrlSegment($parts[1]);

            $claims = json_decode($decoded, true) ?? [];
        } catch (\Throwable $e) {
            throw new JwtException('Failed to parse token claims: ' . $e->getMessage(), 400);
        }

        $scene = $claims['jwt_scene'] ?? 'default';

        // 设置场景并获取配置
        $this->setScene($scene);
        $supportedAlgs = $this->getConfig('supported_algs', []);
        $alg = $this->getConfig('alg', null);

        // 如果场景配置为空，使用默认配置
        if (empty($supportedAlgs)) {
            $supportedAlgs = $this->supportedAlgs;
        }
        if (empty($alg)) {
            $alg = 'HS256';
        }

        if (!isset($supportedAlgs[$alg])) {
            throw new JwtException('Invalid JWT algorithm: ' . $alg, 400);
        }

        $signer = new $supportedAlgs[$alg]();
        return JwtConstant::getParser($signer, $this->getKey())->parse($token);
    }

    /**
     * JWT 各段使用 base64url 编码，解析场景前需要先转换回标准 base64.
     */
    private function decodeBase64UrlSegment(string $segment): string
    {
        $normalized = strtr($segment, '-_', '+/');
        $padding = (4 - strlen($normalized) % 4) % 4;
        if ($padding > 0) {
            $normalized .= str_repeat('=', $padding);
        }

        $decoded = base64_decode($normalized, true);
        if ($decoded === false) {
            throw new JwtException('Invalid token payload', 400);
        }

        return $decoded;
    }
}
