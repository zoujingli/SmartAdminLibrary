<?php

declare(strict_types=1);
/**
 * This file is part of SmartAdmin.
 *
 * @contact Anyon <zoujingli@qq.com>
 * @license https://github.com/zoujingli/SmartAdmin/blob/master/LICENSE
 * @document https://github.com/zoujingli/SmartAdmin/blob/master/readme.md
 */

namespace Library\Helper;

use Hyperf\Context\ApplicationContext;
use Hyperf\Context\Context;
use Hyperf\HttpServer\Contract\RequestInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * 请求处理助手类
 * 统一处理请求上下文、代理头、URL、IP、UserAgent、地理位置等访问信息。
 */
final class RequestHelper
{
    public const URL_WS = 2;

    public const URL_HTTP = 1;

    private const IP2REGION_PREHEAT_IP = '61.142.115.207';

    /** @var null|\Ip2Region IP 地址查询器；只读服务实例不携带请求态，可跨协程复用。 */
    private static ?\Ip2Region $ip2Region = null;

    /**
     * 获取当前协程请求对象。
     *
     * 请求对象必须从协程上下文或容器代理即时读取，不能缓存在静态属性中，避免高并发下串请求。
     */
    public static function getRequest(): ?ServerRequestInterface
    {
        $hasRequestContext = false;

        foreach ([ServerRequestInterface::class, RequestInterface::class] as $abstract) {
            try {
                $hasRequestContext = Context::has($abstract) || $hasRequestContext;
                // CLI、定时任务和同步命令没有 HTTP 请求上下文，Context 代理可能抛出类型错误；这里按无请求软降级。
                $request = Context::get($abstract);
            } catch (\Throwable) {
                continue;
            }

            if ($request instanceof ServerRequestInterface) {
                return $request;
            }
        }

        if (!$hasRequestContext) {
            return null;
        }

        try {
            $container = ApplicationContext::getContainer();
            foreach ([ServerRequestInterface::class, RequestInterface::class] as $abstract) {
                if (!$container->has($abstract)) {
                    continue;
                }

                $request = $container->get($abstract);
                if ($request instanceof ServerRequestInterface) {
                    return $request;
                }
            }
        } catch (\Throwable) {
            // 无容器或非 HTTP 运行场景时软降级，调用方按空请求处理。
        }

        return null;
    }

    /**
     * 获取客户端真实IP地址。
     *
     * 代理链按 Forwarded、X-Forwarded-For、X-Real-IP、X-Client-IP、CF-Connecting-IP、remote_addr 的顺序解析。
     * 无请求上下文时返回 0.0.0.0，保证日志与异步场景不会因缺少请求对象抛错。
     */
    public static function getClientIp(?ServerRequestInterface $request = null): string
    {
        $request ??= self::getRequest();
        if (!$request instanceof ServerRequestInterface) {
            return '0.0.0.0';
        }

        $forwarded = self::validIpFromHeaderValue(self::getForwardedValue($request, 'for'));
        if ($forwarded !== null) {
            return $forwarded;
        }

        foreach (['X-Forwarded-For', 'X-Real-IP', 'X-Client-IP', 'CF-Connecting-IP'] as $header) {
            $ip = self::validIpFromHeaderValue(self::firstHeaderValue($request, $header));
            if ($ip !== null) {
                return $ip;
            }
        }

        $serverParams = $request->getServerParams();
        return self::validIpFromHeaderValue((string)($serverParams['remote_addr'] ?? '')) ?? '0.0.0.0';
    }

    /**
     * 解析当前请求协议。
     *
     * type 控制输出协议族：URL_HTTP 返回 http/https，URL_WS 返回 ws/wss。
     */
    public static function getScheme(int $type = self::URL_HTTP, ?ServerRequestInterface $request = null): ?string
    {
        $request ??= self::getRequest();
        if (!$request instanceof ServerRequestInterface) {
            return null;
        }

        // 代理头可能被错误配置为非 HTTP/WS 协议，遇到非法值继续按优先级向后兜底。
        foreach ([
            self::firstHeaderValue($request, 'X-Scheme'),
            self::getForwardedValue($request, 'proto'),
            self::firstHeaderValue($request, 'X-Forwarded-Proto'),
            trim((string)$request->getUri()->getScheme()),
        ] as $scheme) {
            if ($scheme === null || $scheme === '') {
                continue;
            }

            $mapped = self::mapScheme($scheme, $type);
            if ($mapped !== null) {
                return $mapped;
            }
        }

        $secure = self::getPort($request) === 443;
        return $type === self::URL_WS
            ? ($secure ? 'wss' : 'ws')
            : ($secure ? 'https' : 'http');
    }

    /**
     * 解析当前访问域名，不包含端口。
     */
    public static function getDomain(?ServerRequestInterface $request = null): ?string
    {
        $request ??= self::getRequest();
        if (!$request instanceof ServerRequestInterface) {
            return null;
        }

        return self::resolveHost($request)['host'];
    }

    /**
     * 解析当前访问端口。
     *
     * 域名头中自带端口时优先使用，其次读取显式代理端口，最后回退到 URI 端口。
     */
    public static function getPort(?ServerRequestInterface $request = null): ?int
    {
        $request ??= self::getRequest();
        if (!$request instanceof ServerRequestInterface) {
            return null;
        }

        $host = self::resolveHeaderHost($request);
        if ($host['port'] !== null) {
            return $host['port'];
        }

        foreach (['X-Port', 'X-Forwarded-Port'] as $header) {
            $port = self::normalizePort(self::firstHeaderValue($request, $header));
            if ($port !== null) {
                return $port;
            }
        }

        return self::normalizePort($request->getUri()->getPort());
    }

    /**
     * 生成当前请求 origin。
     *
     * 默认端口 80/443 不拼接；无请求或无域名时返回 null，由上层决定是否降级为相对路径。
     */
    public static function getOrigin(int $type = self::URL_HTTP, ?ServerRequestInterface $request = null): ?string
    {
        $request ??= self::getRequest();
        if (!$request instanceof ServerRequestInterface) {
            return null;
        }

        $domain = self::getDomain($request);
        if ($domain === null || $domain === '') {
            return null;
        }

        $scheme = self::getScheme($type, $request);
        if ($scheme === null) {
            $scheme = $type === self::URL_WS ? 'ws' : 'http';
        }

        $origin = $scheme . '://' . self::formatHostForOrigin($domain);
        $port = self::getPort($request);
        if ($port !== null && !self::isDefaultPort($scheme, $port)) {
            $origin .= ':' . $port;
        }

        return $origin;
    }

    /**
     * 根据当前请求生成 URL。
     *
     * path 为绝对 URL 时保留原地址，仅追加 query；无请求 origin 时输出相对路径，适配 CLI/异步任务。
     *
     * @param array<string, mixed> $query
     */
    public static function url(
        string $path = '',
        array $query = [],
        int $type = self::URL_HTTP,
        ?ServerRequestInterface $request = null
    ): string {
        if (self::isAbsoluteUrl($path)) {
            return self::appendQuery($path, $query);
        }

        $origin = self::getOrigin($type, $request);
        $path = trim($path);
        if ($path === '') {
            return self::appendQuery($origin ?? '/', $query);
        }

        $path = '/' . ltrim($path, '/');
        $url = $origin !== null ? rtrim($origin, '/') . $path : $path;

        return self::appendQuery($url, $query);
    }

    /**
     * 检查IP是否为内网地址
     * @param string $ip IP地址
     * @return bool 是否为内网地址
     */
    public static function isPrivateIp(string $ip): bool
    {
        return !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }

    /**
     * 获取 IP 归属地展示文本。
     *
     * 日志库只有 `ip_location` 一个字段，统一保存 ip2region 官方格式化结果，避免维护省、市、运营商等拆分字段。
     */
    public static function getIpLocation(string $ip): string
    {
        if (empty($ip) || in_array($ip, ['127.0.0.1', '::1'], true)) {
            return '本地';
        }

        if (self::isPrivateIp($ip)) {
            return '内网';
        }

        try {
            // simple() 由 ip2region 负责格式化展示，例如：中国辽宁省沈阳市联通【CN】。
            $location = trim((string)self::getIp2Region()->simple($ip));
            return $location !== '' ? $location : 'Unknown';
        } catch (\Throwable) {
            return 'Unknown';
        }
    }

    /**
     * 获取IP归属地（简化版本）.
     * @param string $ip IP地址
     * @return string 归属地信息
     */
    public static function getIpLocationSimple(string $ip): string
    {
        return self::getIpLocation($ip);
    }

    /**
     * 启动阶段预热 IP2region 查询器。
     *
     * 只初始化无请求态的只读查询实例；传入公网 IP 时额外执行一次真实查询，让 Phar/二进制包场景下的数据文件读取提前发生。
     */
    public static function warmupIp2Region(string $ip = self::IP2REGION_PREHEAT_IP): void
    {
        $ip2Region = self::getIp2Region();
        $ip = trim($ip);
        if ($ip === '' || self::isPrivateIp($ip)) {
            return;
        }

        $ip2Region->simple($ip);
    }

    /**
     * 解析操作系统类型.
     * @param string $userAgent UserAgent字符串
     * @return string 操作系统类型
     */
    public static function parseOS(string $userAgent): string
    {
        if ($userAgent === '') {
            return 'Unknown';
        }

        return match (true) {
            preg_match('/Windows/i', $userAgent) === 1 => 'Windows',
            preg_match('/Macintosh|Mac OS/i', $userAgent) === 1 => 'macOS',
            preg_match('/Android/i', $userAgent) === 1 => 'Android',
            preg_match('/iPhone|iPad|iPod|iOS/i', $userAgent) === 1 => 'iOS',
            preg_match('/Linux/i', $userAgent) === 1 => 'Linux',
            default => 'Unknown',
        };
    }

    /**
     * 解析浏览器类型.
     * @param string $userAgent UserAgent字符串
     * @return string 浏览器类型
     */
    public static function parseBrowser(string $userAgent): string
    {
        if ($userAgent === '') {
            return 'Unknown';
        }

        return match (true) {
            preg_match('/Edg\//i', $userAgent) === 1 => 'Edge',
            preg_match('/OPR\/|Opera/i', $userAgent) === 1 => 'Opera',
            preg_match('/Firefox\//i', $userAgent) === 1 => 'Firefox',
            preg_match('/Chrome\//i', $userAgent) === 1 => 'Chrome',
            preg_match('/Safari\//i', $userAgent) === 1 => 'Safari',
            default => 'Unknown',
        };
    }

    /**
     * 解析设备类型.
     * @param string $userAgent UserAgent字符串
     * @return string 设备类型
     */
    public static function parseDevice(string $userAgent): string
    {
        if ($userAgent === '') {
            return 'Unknown';
        }

        return match (true) {
            preg_match('/Tablet|iPad/i', $userAgent) === 1 => 'Tablet',
            preg_match('/Mobile|Android|iPhone/i', $userAgent) === 1 => 'Mobile',
            default => 'Desktop',
        };
    }

    /**
     * 获取完整的请求信息.
     * @param null|ServerRequestInterface $request 请求对象
     * @return array 完整的请求信息
     */
    public static function getRequestInfo(?ServerRequestInterface $request = null): array
    {
        $request ??= self::getRequest();
        $userAgent = $request?->getHeaderLine('User-Agent') ?? '';
        $ip = $request instanceof ServerRequestInterface ? self::getClientIp($request) : '0.0.0.0';
        $location = $request instanceof ServerRequestInterface ? self::getIpLocation($ip) : 'Unknown';

        return [
            'ip' => $ip,
            'location' => $location,
            'os' => self::parseOS($userAgent),
            'browser' => self::parseBrowser($userAgent),
            'device' => self::parseDevice($userAgent),
            'user_agent' => $userAgent,
            'is_private_ip' => self::isPrivateIp($ip),
        ];
    }

    /**
     * 获取简化的请求信息.
     * @param null|ServerRequestInterface $request 请求对象
     * @return array 简化的请求信息
     */
    public static function getSimpleRequestInfo(?ServerRequestInterface $request = null): array
    {
        $request ??= self::getRequest();
        $userAgent = $request?->getHeaderLine('User-Agent') ?? '';
        $ip = $request instanceof ServerRequestInterface ? self::getClientIp($request) : '0.0.0.0';

        return [
            'ip' => $ip,
            'location' => $request instanceof ServerRequestInterface ? self::getIpLocationSimple($ip) : 'Unknown',
            'os' => self::parseOS($userAgent),
            'browser' => self::parseBrowser($userAgent),
            'device' => self::parseDevice($userAgent),
        ];
    }

    /**
     * 获取IP地址查询器实例.
     */
    private static function getIp2Region(): \Ip2Region
    {
        if (self::$ip2Region === null) {
            self::$ip2Region = new \Ip2Region('content');
        }
        return self::$ip2Region;
    }

    /**
     * 读取首个非空头值；代理头可能包含逗号分隔的链路值，只取第一段表达客户端侧语义。
     */
    private static function firstHeaderValue(ServerRequestInterface $request, string $name): ?string
    {
        foreach ($request->getHeader($name) as $line) {
            foreach (explode(',', $line) as $value) {
                $value = trim($value);
                if ($value !== '') {
                    return trim($value, "\"'");
                }
            }
        }

        return null;
    }

    /**
     * 解析 RFC 7239 Forwarded 头中的指定字段。
     */
    private static function getForwardedValue(ServerRequestInterface $request, string $key): ?string
    {
        $key = strtolower($key);
        foreach ($request->getHeader('Forwarded') as $line) {
            foreach (explode(',', $line) as $node) {
                foreach (explode(';', $node) as $part) {
                    $pair = explode('=', trim($part), 2);
                    if (count($pair) !== 2 || strtolower(trim($pair[0])) !== $key) {
                        continue;
                    }

                    $value = trim($pair[1]);
                    $value = trim($value, "\"'");
                    if ($value !== '') {
                        return $value;
                    }
                }
            }
        }

        return null;
    }

    /**
     * 从代理头中提取第一个合法 IP，兼容 IPv4:port 与 [IPv6]:port 写法。
     */
    private static function validIpFromHeaderValue(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        foreach (explode(',', $value) as $candidate) {
            $ip = self::normalizeIpCandidate($candidate);
            if (filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                return $ip;
            }
        }

        return null;
    }

    private static function normalizeIpCandidate(string $candidate): string
    {
        $candidate = trim($candidate);
        $candidate = trim($candidate, "\"'");

        if (preg_match('/^\[([0-9a-f:.]+)](?::\d+)?$/i', $candidate, $matches) === 1) {
            return $matches[1];
        }

        if (preg_match('/^(\d{1,3}(?:\.\d{1,3}){3})(?::\d+)?$/', $candidate, $matches) === 1) {
            return $matches[1];
        }

        return trim($candidate, '[]');
    }

    /**
     * 按自定义头、标准代理头、原始 Host、URI 的顺序解析域名来源。
     *
     * @return array{host:?string,port:?int}
     */
    private static function resolveHost(ServerRequestInterface $request): array
    {
        $host = self::resolveHeaderHost($request);
        if ($host['host'] !== null) {
            return $host;
        }

        $uri = $request->getUri();
        $value = $uri->getHost();
        if ($value !== '' && $uri->getPort() !== null) {
            $value = (str_contains($value, ':') ? '[' . trim($value, '[]') . ']' : $value) . ':' . $uri->getPort();
        }

        return self::parseHostPort($value);
    }

    /**
     * 只解析代理/Host 头中的主机，端口优先级不能提前混入 URI port。
     *
     * @return array{host:?string,port:?int}
     */
    private static function resolveHeaderHost(ServerRequestInterface $request): array
    {
        foreach ([
            self::firstHeaderValue($request, 'X-Host'),
            self::getForwardedValue($request, 'host'),
            self::firstHeaderValue($request, 'X-Forwarded-Host'),
            self::firstHeaderValue($request, 'Host'),
        ] as $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $host = self::parseHostPort($value);
            if ($host['host'] !== null) {
                return $host;
            }
        }

        return ['host' => null, 'port' => null];
    }

    /**
     * 解析 host[:port]，域名可能来自代理头，不能信任一定是规范 URI。
     *
     * @return array{host:?string,port:?int}
     */
    private static function parseHostPort(string $value): array
    {
        $value = trim($value);
        $value = trim($value, "\"'");
        if ($value === '') {
            return ['host' => null, 'port' => null];
        }

        if (str_contains($value, '://')) {
            $parsed = parse_url($value);
        } elseif (str_starts_with($value, '[')) {
            $parsed = parse_url('http://' . $value);
        } elseif (substr_count($value, ':') > 1) {
            return ['host' => trim($value, '[]'), 'port' => null];
        } else {
            $parsed = parse_url('http://' . $value);
        }

        if (!is_array($parsed) || empty($parsed['host'])) {
            return ['host' => null, 'port' => null];
        }

        return [
            'host' => trim((string)$parsed['host'], '[]'),
            'port' => self::normalizePort($parsed['port'] ?? null),
        ];
    }

    private static function normalizePort(mixed $port): ?int
    {
        if ($port === null || $port === '') {
            return null;
        }

        if (!is_numeric($port)) {
            return null;
        }

        $port = (int)$port;
        return $port >= 1 && $port <= 65535 ? $port : null;
    }

    /**
     * 将 HTTP/WS 协议映射到调用方指定协议族。
     */
    private static function mapScheme(string $scheme, int $type): ?string
    {
        $scheme = strtolower(trim($scheme));
        $scheme = rtrim($scheme, ':/');
        if (!in_array($scheme, ['http', 'https', 'ws', 'wss'], true)) {
            return null;
        }

        $secure = in_array($scheme, ['https', 'wss'], true);
        if ($type === self::URL_WS) {
            return $secure ? 'wss' : 'ws';
        }

        return $secure ? 'https' : 'http';
    }

    private static function formatHostForOrigin(string $host): string
    {
        return str_contains($host, ':') && !str_starts_with($host, '[') ? '[' . $host . ']' : $host;
    }

    private static function isDefaultPort(string $scheme, int $port): bool
    {
        return match ($scheme) {
            'http', 'ws' => $port === 80,
            'https', 'wss' => $port === 443,
            default => false,
        };
    }

    private static function isAbsoluteUrl(string $path): bool
    {
        return preg_match('#^[a-z][a-z0-9+.-]*://#i', $path) === 1 || str_starts_with($path, '//');
    }

    /**
     * 使用 http_build_query 统一 query 序列化，并保留 URL fragment。
     *
     * @param array<string, mixed> $query
     */
    private static function appendQuery(string $url, array $query): string
    {
        $queryString = http_build_query($query);
        if ($queryString === '') {
            return $url;
        }

        $fragment = '';
        $fragmentPosition = strpos($url, '#');
        if ($fragmentPosition !== false) {
            $fragment = substr($url, $fragmentPosition);
            $url = substr($url, 0, $fragmentPosition);
        }

        return $url . (str_contains($url, '?') ? '&' : '?') . $queryString . $fragment;
    }

}
