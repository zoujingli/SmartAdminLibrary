<?php

declare(strict_types=1);
/**
 * This file is part of SmartAdmin.
 *
 * @contact Anyon <zoujingli@qq.com>
 * @license https://github.com/zoujingli/SmartAdmin/blob/master/LICENSE
 * @document https://zoujingli.github.io/SmartAdmin
 */

namespace Library\Middleware;

use Hyperf\Context\Context;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class CorsMiddleware implements MiddlewareInterface
{
    /**
     * 允许的跨域请求头。
     *
     * Website 开放接口使用 X-Website-* 自定义签名头；预检请求不会进入业务控制器，
     * 需要在全局 CORS 层放行，否则跨域对接环境会在浏览器预检阶段被拦截。
     */
    private const ALLOW_HEADERS = 'Content-Type, Authorization, X-Requested-With, Accept-Language, Lang, X-Website-Appid, X-Website-Timestamp, X-Website-Nonce, X-Website-Sign';

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($request->getMethod() === 'OPTIONS') {
            $response = Context::get(ResponseInterface::class);
            return $this->applyCors($response, $request)->withStatus(200);
        }

        $response = $handler->handle($request);
        return $this->applyCors($response, $request);
    }

    private function applyCors(ResponseInterface $response, ServerRequestInterface $request): ResponseInterface
    {
        $origin = trim($request->getHeaderLine('Origin'));
        $allowOrigin = $origin !== '' ? $origin : '*';

        $response = $response
            ->withHeader('Access-Control-Allow-Origin', $allowOrigin)
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
            ->withHeader('Access-Control-Allow-Headers', self::ALLOW_HEADERS)
            ->withHeader('Access-Control-Max-Age', '86400');

        if ($allowOrigin !== '*') {
            $response = $response
                ->withHeader('Access-Control-Allow-Credentials', 'true')
                ->withHeader('Vary', 'Origin');
        }

        return $response;
    }
}
