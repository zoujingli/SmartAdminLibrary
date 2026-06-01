<?php

declare(strict_types=1);
/**
 * This file is part of SmartAdmin.
 *
 * @contact Anyon <zoujingli@qq.com>
 * @license https://github.com/zoujingli/SmartAdmin/blob/master/LICENSE
 * @document https://zoujingli.github.io/SmartAdmin
 */
use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\StdoutLoggerInterface;
use Hyperf\Database\Model\Builder as ModelBuilder;
use Hyperf\Database\Query\Builder as QueryBuilder;
use Library\Auth\Token;
use Library\Constants\System;
use Library\Exception\CoreResponseException;
use Library\Helper\QueryHelper;
use Library\Helper\TaskExtend;
use Library\Helper\ValidateHelper;
use Library\Interfaces\UserModelInterface;
use Library\Service\LoginService;
use Library\Support\TenantContext;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\VarDumper\VarDumper;
use System\Model\SystemUser;

use function Hyperf\Support\class_basename;

/*
 * SmartAdmin 全局函数库
 * 提供容器操作、用户获取、日志记录等核心功能
 */

if (!function_exists('dump')) {
    /**
     * 友好变量输出
     * 支持Symfony VarDumper美化输出.
     */
    function dump(mixed $args): void
    {
        if (class_exists(VarDumper::class)) {
            VarDumper::dump($args);
        } else {
            var_dump($args);
        }
    }
}

if (!function_exists('_project_path')) {
    /**
     * 解析项目根目录，兼容 BASE_PATH 未定义的场景。
     */
    function _project_path(): string
    {
        static $base;
        if (is_string($base) && $base !== '') {
            return $base;
        }

        $candidates = [];
        if (defined('BASE_PATH') && BASE_PATH !== '') {
            $candidates[] = (string)BASE_PATH;
        }

        $cwd = getcwd();
        if (is_string($cwd) && $cwd !== '') {
            $candidates[] = $cwd;
        }

        $dir = __DIR__;
        for ($depth = 0; $depth < 8; ++$depth) {
            $candidates[] = $dir;
            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }

        foreach ($candidates as $candidate) {
            $candidate = rtrim((string)$candidate, '/\\');
            if (
                $candidate !== ''
                && is_file($candidate . '/composer.json')
                && (is_dir($candidate . '/config') || is_dir($candidate . '/plugin') || is_dir($candidate . '/vendor'))
            ) {
                return $base = $candidate;
            }
        }

        return $base = rtrim((is_string($cwd) && $cwd !== '') ? $cwd : dirname(__DIR__, 2), '/\\');
    }
}

if (!function_exists('syspath')) {
    /**
     * 获取系统内部路径，Phar 模式下返回包内路径。
     *
     * 适合读取随系统一起发布的配置、语言包、模板、静态资源等。
     */
    function syspath(string $path = ''): string
    {
        static $base;
        $base ??= rtrim(System::isPharMode() ? 'phar://' . Phar::running(false) : _project_path(), '/\\');

        return $path === '' ? $base : $base . '/' . ltrim($path, '/\\');
    }
}

if (!function_exists('runpath')) {
    /**
     * 获取运行时外部路径，Phar 模式下返回 phar 文件同级目录。
     *
     * 适合写入 runtime、public、日志、缓存、上传文件等运行环境数据。
     */
    function runpath(string $path = ''): string
    {
        static $base;
        $base ??= rtrim(System::isPharMode() ? dirname(Phar::running(false)) : _project_path(), '/\\');

        return $path === '' ? $base : $base . '/' . ltrim($path, '/\\');
    }
}

if (!function_exists('tenant_id')) {
    /**
     * 读取或设置当前租户 ID。
     */
    function tenant_id(?int $tenantId = null): int
    {
        return $tenantId === null
            ? TenantContext::get()
            : TenantContext::set($tenantId);
    }
}

if (!function_exists('is_platform_tenant')) {
    /**
     * 当前账号是否具备平台运维能力。
     */
    function is_platform_tenant(): bool
    {
        return TenantContext::isPlatform();
    }
}

if (!function_exists('_once')) {
    /**
     * 获取容器内的实例对象
     * @template T
     * @param class-string<T> $id
     * @return T
     */
    function _once(string $id)
    {
        try {
            return ApplicationContext::getContainer()->get($id);
        } catch (Exception|Throwable $exception) {
            throw new CoreResponseException($exception->getMessage(), null, $exception->getCode(), $exception);
        }
    }
}

if (!function_exists('_vali')) {
    /**
     * 快捷数据验证
     *
     * 使用预定义的验证规则快速验证输入数据
     *
     * @param array $rules 验证规则数组
     * @param array|string $input 要验证的输入数据
     * @return array 验证结果
     */
    function _vali(array $rules, array|string $input = ''): array
    {
        return make(ValidateHelper::class)->check($rules, $input);
    }
}

if (!function_exists('_task')) {
    /**
     * 派发后台协程任务。
     *
     * 任务名称必须稳定，底层按租户和名称加锁；重复投递返回正在执行的 task_id，供前端继续轮询进度。
     */
    function _task(string $name, Closure $callback, int $locktime = 300): string
    {
        return _once(TaskExtend::class)->dispatch($name, $callback, $locktime);
    }
}

if (!function_exists('_cache')) {
    /**
     * 数据缓存读写.
     *
     * 提供统一的缓存操作接口，支持读取、写入和删除操作
     *
     * @param string $name 缓存键名
     * @param mixed $value 要缓存的数据（为空时表示读取）
     * @param mixed $expire 缓存过期时间（秒）
     * @return mixed 读取时返回缓存数据，写入时返回原数据
     */
    function _cache(string $name, mixed $value = '', mixed $expire = null): mixed
    {
        try {
            $cache = _once(CacheInterface::class);
            if (is_null($expire) && (is_null($value) || $value === '')) {
                return is_null($value) ? $cache->delete($name) : $cache->get($name);
            }
            $cache->set($name, $value, $expire);
            return $value;
        } catch (Throwable $throwable) {
            throw new CoreResponseException($throwable->getMessage(), null, $throwable->getCode(), $throwable);
        }
    }
}

if (!function_exists('_query')) {
    /**
     * 快捷查询构建器.
     *
     * 创建查询助手对象，提供便捷的数据库查询方法
     *
     * @param ModelBuilder|QueryBuilder|string $query 查询构建器实例或表名
     * @param array|string $input 输入参数（默认为 'all' 表示所有参数）
     * @param ?callable $callable 自定义回调函数
     * @return QueryHelper 查询助手对象
     */
    function _query(ModelBuilder|QueryBuilder|string $query, array|string $input = 'all', ?callable $callable = null): QueryHelper
    {
        return make(QueryHelper::class)->withQuery($query, $input, $callable);
    }
}

if (!function_exists('_trace')) {
    /**
     * 异常日志输出.
     */
    function _trace(Throwable $throwable, bool $write = true): string
    {
        $message = sprintf('%s(%s): %s', class_basename($throwable), $throwable->getCode(), $throwable->getMessage());
        $message .= PHP_EOL . sprintf('## %s(%s): %s(...)', $throwable->getFile(), $throwable->getLine(), get_class($throwable));
        $message .= PHP_EOL . $throwable->getTraceAsString();

        try {
            $logger = $write ? _once(LoggerInterface::class) : _once(StdoutLoggerInterface::class);
            $logger->error($message);
        } catch (Throwable) {
            error_log($message);
        }

        return $message;
    }
}

if (!function_exists('make')) {
    /**
     * Create an object instance, if the DI container exist in ApplicationContext,
     * then the object will be created by DI container via `make()` method, if not,
     * the object will create by `new` keyword.
     */
    function make(string $name, array $parameters = [])
    {
        return \Hyperf\Support\make($name, $parameters);
    }
}

if (!function_exists('str2arr')) {
    /**
     * 字符串转数组.
     * @param null|array|int|string $text 待转内容
     * @param string $separ 分隔字符
     * @param ?array $allow 限定规则
     */
    function str2arr(array|int|string|null $text, string $separ = ',', ?array $allow = null): array
    {
        $items = [];
        foreach (is_array($text) ? $text : explode($separ, trim("{$text}", $separ)) as $item) {
            if ($item !== '' && (!is_array($allow) || in_array($item, $allow))) {
                $items[] = trim("{$item}");
            }
        }
        return $items;
    }
}

if (!function_exists('arr2str')) {
    /**
     * 数组转字符串.
     * @param array|int|string $data 待转数组
     * @param string $separ 分隔字符
     * @param ?array $allow 限定规则
     */
    function arr2str(array|int|string $data, string $separ = ',', ?array $allow = null): string
    {
        foreach (($data = is_string($data) || is_numeric($data) ? str2arr("{$data}") : $data) as $key => $item) {
            if ($item === '' || (is_array($allow) && !in_array($item, $allow))) {
                unset($data[$key]);
            }
        }
        return $separ . join($separ, $data) . $separ;
    }
}

if (!function_exists('user')) {
    /**
     * 获取当前登录用户实例
     * 支持指定登录用户模型类名，默认获取 System 后台用户。
     */
    function user(string $userModel = SystemUser::class, ?string $token = null): ?UserModelInterface
    {
        try {
            return _once(LoginService::class)->getUser($token, $userModel);
        } catch (Throwable $throwable) {
            return null;
        }
    }
}

if (!function_exists('auth_claims')) {
    /**
     * 获取当前请求 Token 的声明信息。
     * 仅在令牌校验通过后返回声明，不触发数据库查询。
     *
     * @return array<string, mixed>
     */
    function auth_claims(?string $token = null): array
    {
        try {
            $claims = _once(Token::class)->getParserData($token);

            return is_array($claims) ? $claims : [];
        } catch (Throwable) {
            return [];
        }
    }
}

if (!function_exists('is_super_login')) {
    /**
     * 判断当前登录态是否为超级管理员。
     * 基于当前有效登录用户判定，避免禁用用户仍被超级管理员短路放行。
     */
    function is_super_login(?array $claims = null): bool
    {
        $claims = is_array($claims) ? $claims : auth_claims();
        $userModel = (string)($claims['class'] ?? SystemUser::class);
        $currentUser = user($userModel);

        return $currentUser?->isSuper() ?? false;
    }
}
