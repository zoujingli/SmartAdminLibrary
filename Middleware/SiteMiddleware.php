<?php

declare(strict_types=1);
/**
 * This file is part of SmartAdmin.
 *
 * @contact Anyon <zoujingli@qq.com>
 * @license https://github.com/zoujingli/SmartAdmin/blob/master/LICENSE
 * @document https://github.com/zoujingli/SmartAdmin/blob/master/readme.md
 */

namespace Library\Middleware;

use Hyperf\Codec\Json;
use Hyperf\HttpMessage\Exception\NotFoundHttpException;
use Hyperf\HttpMessage\Stream\SwooleStream;
use Hyperf\HttpServer\CoreMiddleware;
use Psr\Http\Message\ServerRequestInterface;

use function Hyperf\Support\env;

/**
 * 站点中间件，无匹配路由时提供前端静态资源与动态配置.
 */
final class SiteMiddleware extends CoreMiddleware
{
    protected function handleNotFound(ServerRequestInterface $request): mixed
    {
        $method = $request->getMethod();
        if (!in_array($method, ['GET', 'HEAD'], true)) {
            throw new NotFoundHttpException();
        }

        $path = $request->getUri()->getPath();

        if ($path === '/_app.config.js') {
            return $this->respondAppConfig($method);
        }

        $rel = match (true) {
            $path === '' || $path === '/' => '/index.html',
            default => $path,
        };

        $inner = $this->normalizeWebsiteRelativePath($rel);
        if ($inner === null) {
            throw new NotFoundHttpException();
        }

        $full = $this->resolveWebsiteFile($inner);
        if ($full === null && $this->shouldUseSpaHistoryFallback($request, $inner)) {
            // 前端采用 history 模式，浏览器刷新任意页面路由时返回构建后的 SPA 入口文件；
            // 接口请求、缺失静态资源和带扩展名的文件仍继续走标准 404 响应。
            $full = $this->resolveWebsiteFile('index.html');
        }
        if ($full === null) {
            throw new NotFoundHttpException();
        }

        $fileNorm = str_replace('\\', '/', $full);
        $lower = strtolower($fileNorm);
        $isHtml = str_ends_with($lower, '.html') || str_ends_with($lower, '.htm');

        $lastModified = gmdate('D, d M Y H:i:s \G\M\T', (int)filemtime($full));
        $response = $this->response()->addHeader('Last-Modified', $lastModified);

        if ($request->getHeaderLine('If-Modified-Since') === $lastModified) {
            return $response->withStatus(304)->setBody(new SwooleStream(''));
        }

        $body = $method === 'HEAD' ? '' : (string)file_get_contents($full);

        if ($isHtml) {
            $response = $response
                ->addHeader('Cache-Control', 'public, max-age=0')
                ->addHeader('Expires', gmdate('D, d M Y H:i:s \G\M\T', time()));
        } else {
            $response = $response
                ->addHeader('Cache-Control', 'public, max-age=2592000')
                ->addHeader('Expires', gmdate('D, d M Y H:i:s \G\M\T', time() + 2592000));
        }

        return $response
            ->addHeader('Content-Type', $this->guessMimeType($full))
            ->setBody(new SwooleStream($body));
    }

    /**
     * 动态生成 _app.config.js，将 .env 配置注入前端运行时.
     */
    private function respondAppConfig(string $method): mixed
    {
        $config = [
            'VITE_GLOB_API_URL' => env('APP_API_URL', '/'),
        ];

        $title = env('APP_TITLE');
        if ($title !== null && $title !== '') {
            $config['APP_TITLE'] = $title;
        }

        $variable = '_VBEN_ADMIN_PRO_APP_CONF_';
        $json = Json::encode($config);
        $source = "window.{$variable}={$json};"
            . "Object.freeze(window.{$variable});"
            . "Object.defineProperty(window,\"{$variable}\",{configurable:false,writable:false});";

        $body = $method === 'HEAD' ? '' : $source;
        return $this->response()
            ->addHeader('Content-Type', 'application/javascript; charset=utf-8')
            ->addHeader('Cache-Control', 'public, max-age=0')
            ->addHeader('Expires', gmdate('D, d M Y H:i:s \G\M\T', time()))
            ->setBody(new SwooleStream($body));
    }

    /**
     * 规范化 URI 路径，防止目录穿越，返回相对路径或 null.
     */
    private function normalizeWebsiteRelativePath(string $relUriPath): ?string
    {
        $trimmed = ltrim($relUriPath, '/');
        if ($trimmed === '') {
            return 'index.html';
        }

        $segments = explode('/', $trimmed);
        $safe = [];
        foreach ($segments as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                return null;
            }
            $safe[] = $seg;
        }

        return $safe === [] ? null : implode('/', $safe);
    }

    /**
     * 解析前端构建目录中的真实文件，统一保护目录穿越和 Phar/源码两种运行形态。
     */
    private function resolveWebsiteFile(string $inner): ?string
    {
        $root = $this->websiteRoot();
        $full = $root . '/' . $inner;
        if (!is_file($full)) {
            return null;
        }

        $rootNorm = str_replace('\\', '/', rtrim($root, '/\\'));
        $fileNorm = str_replace('\\', '/', $full);
        if ($fileNorm !== $rootNorm && !str_starts_with($fileNorm, $rootNorm . '/')) {
            return null;
        }

        $realRoot = realpath($root);
        $realFile = realpath($full);
        if ($realRoot === false || $realFile === false || !str_starts_with($realFile, $realRoot)) {
            return null;
        }

        return $full;
    }

    /**
     * 源码模式读取 web/dist；Phar 模式读取运行目录 public，和启动时自动发布的前端资源保持一致。
     */
    private function websiteRoot(): string
    {
        return $this->isRunningInsidePhar() ? runpath('public') : syspath('web/dist');
    }

    /**
     * 仅浏览器页面导航启用 SPA history 回退，避免把接口或静态资源 404 误返回 HTML。
     */
    private function shouldUseSpaHistoryFallback(ServerRequestInterface $request, string $inner): bool
    {
        if (pathinfo($inner, PATHINFO_EXTENSION) !== '') {
            return false;
        }

        $fetchMode = strtolower($request->getHeaderLine('Sec-Fetch-Mode'));
        if ($fetchMode !== '' && $fetchMode !== 'navigate') {
            return false;
        }

        return str_contains(strtolower($request->getHeaderLine('Accept')), 'text/html');
    }

    private function isRunningInsidePhar(): bool
    {
        return \Phar::running(false) !== '';
    }

    private function guessMimeType(string $file): string
    {
        $map = [
            'js' => 'application/javascript; charset=utf-8',
            'mjs' => 'application/javascript; charset=utf-8',
            'css' => 'text/css; charset=utf-8',
            'html' => 'text/html; charset=utf-8',
            'htm' => 'text/html; charset=utf-8',
            'json' => 'application/json; charset=utf-8',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            'ico' => 'image/x-icon',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf' => 'font/ttf',
            'webp' => 'image/webp',
            'map' => 'application/json; charset=utf-8',
        ];
        $ext = strtolower((string)pathinfo($file, PATHINFO_EXTENSION));
        if (isset($map[$ext])) {
            return $map[$ext];
        }
        if (function_exists('mime_content_type')) {
            $detected = @mime_content_type($file);
            if (is_string($detected) && $detected !== '') {
                return $detected;
            }
        }

        return 'application/octet-stream';
    }
}
