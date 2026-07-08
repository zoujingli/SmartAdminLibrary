<?php

declare(strict_types=1);
/**
 * This file is part of SmartAdmin.
 *
 * @contact Anyon <zoujingli@qq.com>
 * @license https://github.com/zoujingli/SmartAdmin/blob/master/LICENSE
 * @document https://zoujingli.github.io/SmartAdmin
 */

namespace Library\Exception;

use Hyperf\Codec\Json;
use Hyperf\HttpMessage\Stream\SwooleStream;
use Library\Constants\System;
use Library\Helper\RequestHelper;
use Library\Support\PluginManifestRegistry;
use Library\Support\SensitiveDataFilter;
use Psr\Http\Message\ResponseInterface;

use function Hyperf\Translation\__;

class BaseResponseException extends \RuntimeException
{
    /**
     * 标准响应异常也需要携带完整跨域请求头，确保认证失败时浏览器仍能读取 code/info/path 结构。
     */
    private const ALLOW_HEADERS = 'accept-language,authorization,lang,uid,token,Keep-Alive,User-Agent,Cache-Control,Content-Type,X-Requested-With';

    private const STANDARD_CODES = [
        System::SUCCESS,
        System::UNAUTHORIZED,
        System::NOT_ALLOW,
        System::NOT_FOUND,
        System::ERROR,
    ];

    /**
     * 中文消息未显式指定 group 时的基础查找顺序。
     *
     * Library 放通用响应和基础设施文案；业务插件放各自领域文案。运行时还会扫描插件语言目录，
     * 追加业务插件自己的 group，让业务层可以直接抛中文文案。
     */
    private const TRANSLATION_GROUPS = ['library', 'system', 'plugin', 'builder'];

    protected int $status = System::SUCCESS;

    protected mixed $data = null;

    /**
     * 通用响应异常可直接抛出；第 4 个参数兼容旧调用传 Throwable 或历史 status。
     */
    public function __construct(
        mixed $message = '操作成功',
        mixed $data = null,
        mixed $code = System::SUCCESS,
        mixed $status = null,
        ?\Throwable $previous = null,
    ) {
        if ($status instanceof \Throwable) {
            $previous = $status;
            $status = null;
        }

        // 项目只用 body.code 表达业务状态；历史 status 参数仅作为业务码候选，HTTP status 固定 200。
        $code = $status === null ? $this->normalizeStandardCode($code) : $this->normalizeStandardCode($status);
        $this->data = $data;
        $this->status = System::SUCCESS;
        parent::__construct($this->resolveMessage($message), $code, $previous);
    }

    /**
     * 获取异常数据.
     */
    public function getData(): mixed
    {
        return $this->data;
    }

    /**
     * 设置异常数据.
     * @return $this
     */
    public function setData(mixed $data): static
    {
        $this->data = $data;
        return $this;
    }

    /**
     * 设置响应状态
     * @return $this
     */
    public function setStatus(int $status): static
    {
        // 兼容旧链式调用：setStatus 实际调整 body.code，HTTP status 仍固定为 200。
        $this->code = $this->normalizeStandardCode($status);
        $this->status = System::SUCCESS;
        return $this;
    }

    /**
     * 获取响应状态
     */
    public function getStatus(): int
    {
        return $this->status;
    }

    /**
     * 生成数组数据.
     */
    public function toArray(): array
    {
        $path = RequestHelper::getRequest()?->getUri()->getPath() ?? '';

        // 本项目标准消息字段为 info；第三方 message 兼容只在前端错误解析兜底处理。
        return [
            'code' => $this->code,
            'info' => $this->message,
            'data' => $this->data,
            // path 是标准响应体的一部分，公共分享、评价、回调等 URL 可能把一次性凭证放在路径段里，返回前也要脱敏。
            'path' => SensitiveDataFilter::maskText($path),
        ];
    }

    /**
     * 生成JSON数据.
     */
    public function toJson(): string
    {
        return Json::encode($this->toArray());
    }

    /**
     * 设置并返回响应对象
     */
    public function withResponse(?ResponseInterface $response = null): ResponseInterface
    {
        $origin = trim((string)RequestHelper::getRequest()?->getHeaderLine('Origin'));
        $allowOrigin = $origin !== '' ? $origin : '*';

        $resp = ($response ?? \Hyperf\Support\make(ResponseInterface::class))
            ->withHeader('Server', System::getName())
            ->withHeader('Access-Control-Allow-Origin', $allowOrigin)
            ->withHeader('Access-Control-Allow-Methods', 'GET,PUT,POST,DELETE,OPTIONS')
            ->withHeader('Access-Control-Allow-Headers', self::ALLOW_HEADERS)
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withStatus(System::SUCCESS)->withBody(new SwooleStream($this->toJson()));

        if ($allowOrigin !== '*') {
            $resp = $resp
                ->withHeader('Access-Control-Allow-Credentials', 'true')
                ->withHeader('Vary', 'Origin');
        }

        return $resp;
    }

    private function normalizeStandardCode(mixed $code): int
    {
        $code = is_numeric($code) ? (int)$code : System::ERROR;

        return in_array($code, self::STANDARD_CODES, true) ? $code : System::ERROR;
    }

    private function resolveMessage(mixed $message): string
    {
        if (!is_string($message)) {
            return is_scalar($message) || $message instanceof \Stringable ? (string)$message : 'Response exception';
        }

        try {
            if (str_starts_with($message, 'system.') || str_contains($message, '::')) {
                return (string)__($message);
            }

            // 后端业务错误统一允许直接使用中文作为语言 Key；未配置翻译时按原中文显示。
            foreach ($this->translationGroups() as $group) {
                $key = $group . '.' . $message;
                $translated = (string)__($key);
                if ($translated !== $key) {
                    return $translated;
                }
            }

            return $message;
        } catch (\Throwable) {
            return $message;
        }
    }

    /**
     * 获取中文 Key 的翻译 group 候选。
     *
     * 插件语言包目录由 plugin.json 的 plugin.language_root 显式声明；这里通过文件名提取 group，
     * 避免新业务插件必须修改公共异常类。基础 group 固定优先，防止公共文案被业务插件覆盖。
     *
     * @return array<int, string>
     */
    private function translationGroups(): array
    {
        static $groups = null;
        if (is_array($groups)) {
            return $groups;
        }

        $groups = array_fill_keys(self::TRANSLATION_GROUPS, true);
        try {
            foreach (PluginManifestRegistry::languagePaths() as $languageRoot) {
                foreach (new \DirectoryIterator($languageRoot) as $locale) {
                    if ($locale->isDot() || !$locale->isDir()) {
                        continue;
                    }

                    foreach (new \DirectoryIterator($locale->getPathname()) as $file) {
                        if ($file->isFile() && $file->getExtension() === 'php') {
                            $groups[$file->getBasename('.php')] = true;
                        }
                    }
                }
            }
        } catch (\Throwable) {
            return array_keys($groups);
        }

        return array_keys($groups);
    }
}
