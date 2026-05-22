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

use Hyperf\Context\Context;
use Hyperf\Contract\ConfigInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * 抽象 JWT 基类.
 *
 * 该类提供了基础的 JWT 配置管理、缓存操作及场景处理功能。
 * 继承该类可以实现具体的 Token 管理和黑名单逻辑。
 */
abstract class JwtAbstract
{
    private const CONTEXT_SCENE_KEY = '__library.jwt.scene';

    /**
     * 默认场景名称；实际请求场景存入协程 Context，避免 Swoole 单例复用串场景。
     */
    protected string $scene = 'default';

    /**
     * 配置中场景的前缀.
     */
    protected string $scenePrefix = 'scene';

    /**
     * 配置前缀，默认 jwt.
     */
    protected string $configPrefix = 'jwt';

    /**
     * Token 前缀，默认为 Bearer.
     */
    protected string $tokenPrefix = 'Bearer';

    /**
     * Token 中用于记录场景信息的 Claim 名称.
     */
    protected string $tokenScenePrefix = 'jwt_scene';

    /**
     * 支持的签名算法列表.
     */
    protected array $supportedAlgs = [
        'HS256' => 'Lcobucci\JWT\Signer\Hmac\Sha256',
        'HS384' => 'Lcobucci\JWT\Signer\Hmac\Sha384',
        'HS512' => 'Lcobucci\JWT\Signer\Hmac\Sha512',
        'ES256' => 'Lcobucci\JWT\Signer\Ecdsa\Sha256',
        'ES384' => 'Lcobucci\JWT\Signer\Ecdsa\Sha384',
        'ES512' => 'Lcobucci\JWT\Signer\Ecdsa\Sha512',
        'RS256' => 'Lcobucci\JWT\Signer\Rsa\Sha256',
        'RS384' => 'Lcobucci\JWT\Signer\Rsa\Sha384',
        'RS512' => 'Lcobucci\JWT\Signer\Rsa\Sha512',
    ];

    /**
     * 对称算法名称.
     */
    protected array $symmetryAlgs = [
        'HS256', 'HS384', 'HS512',
    ];

    /**
     * 非对称算法名称.
     */
    protected array $asymmetricAlgs = [
        'RS256', 'RS384', 'RS512', 'ES256', 'ES384', 'ES512',
    ];

    /**
     * 构造函数.
     *
     * @param CacheInterface $cache 缓存接口，用于存储黑名单等数据
     * @param ConfigInterface $config 配置接口，用于读取 JWT 配置
     */
    public function __construct(
        protected CacheInterface $cache,
        protected ConfigInterface $config,
    ) {
        $config = $this->config->get($this->configPrefix);

        // 场景配置初始化
        $scenes = $config['scene'] ?? [];
        unset($config['scene']);
        foreach ($scenes as $key => $scene) {
            $this->setSceneConfig($key, array_merge($config, $scene));
        }
    }

    /**
     * 设置当前使用的场景.
     *
     * @param string $scene 场景名称
     */
    public function setScene(string $scene): static
    {
        Context::set(self::CONTEXT_SCENE_KEY, $scene !== '' ? $scene : $this->scene);
        return $this;
    }

    /**
     * 获取当前使用的场景.
     *
     * @return string 场景名称
     */
    public function getScene(): string
    {
        $scene = Context::get(self::CONTEXT_SCENE_KEY);

        return is_string($scene) && $scene !== '' ? $scene : $this->scene;
    }

    /**
     * 设置指定场景的配置.
     *
     * @param string $scene 场景名称
     * @param mixed $value 场景配置值
     */
    public function setSceneConfig(string $scene = 'default', $value = null): static
    {
        $this->config->set("{$this->configPrefix}.{$this->scenePrefix}.{$scene}", $value);
        return $this;
    }

    /**
     * 获取指定场景的配置.
     *
     * @param string $scene 场景名称
     * @return mixed 场景配置
     */
    public function getSceneConfig(string $scene = 'default'): mixed
    {
        return $this->config->get("{$this->configPrefix}.{$this->scenePrefix}.{$scene}");
    }

    /**
     * 获取标准化配置值.
     *
     * 优先从传入的 $config 数组中读取，如果没有则从当前场景配置中读取，
     * 若都没有则返回默认值 $default。
     *
     * @param string $key 配置键名
     * @param mixed $default 默认值
     * @param null|string $scene 场景名称，如为空则使用当前场景
     * @param array $config 临时配置数组
     * @return mixed 配置值
     */
    protected function getConfig(string $key, mixed $default = null, ?string $scene = null, array $config = []): mixed
    {
        if (array_key_exists($key, $config)) {
            return $config[$key];
        }
        $sceneConfig = $this->getSceneConfig($scene ?? $this->getScene());
        return $sceneConfig[$key] ?? $default;
    }
}
