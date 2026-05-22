<?php

declare(strict_types=1);
/**
 * This file is part of SmartAdmin.
 *
 * @contact Anyon <zoujingli@qq.com>
 * @license https://github.com/zoujingli/SmartAdmin/blob/master/LICENSE
 * @document https://github.com/zoujingli/SmartAdmin/blob/master/readme.md
 */

namespace Library\Command;

use FilesystemIterator;
use Hyperf\Command\Annotation\Command;
use Hyperf\Command\Command as HyperfCommand;
use Library\Command\Concerns\SourceOnlyCommand;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

#[Command(name: 'xadmin:docs:check', description: 'Validate docsify files, links and API documentation coverage')]
final class DocsCheck extends HyperfCommand
{
    use SourceOnlyCommand;

    public function configure(): void
    {
        $this->setDescription('Validate docsify files, links and API documentation coverage');
    }

    public function handle(): void
    {
        $root = defined('BASE_PATH') ? (string)constant('BASE_PATH') : dirname(__DIR__, 3);
        $docs = $root . '/docs';
        $errors = [];

        $required = [
            'index.html',
            '.nojekyll',
            'README.md',
            '_404.md',
            '_sidebar.md',
            'assets/api-tester.css',
            'assets/api-tester.js',
            'assets/devapi-docs.css',
            'assets/devapi-mermaid.js',
            'assets/devapi-module-nav.js',
            'assets/devapi-scale.js',
            'assets/devapi-sidebar-state.js',
            '快速开始/README.md',
            '用户教程/README.md',
            '系统功能/README.md',
            '开发指南/README.md',
            '接口参考/README.md',
            '接口参考/在线测试.md',
            '接口参考/完整接口清单.md',
            '架构设计/README.md',
            '部署运维/README.md',
            '开源协作/README.md',
        ];

        foreach ($required as $file) {
            if (!is_file($docs . '/' . $file)) {
                $errors[] = "缺少文档文件: {$file}";
            }
        }
        foreach (['_coverpage.md', '_navbar.md'] as $removed) {
            if (is_file($docs . '/' . $removed)) {
                $errors[] = "文档站已不再使用 {$removed}，请维护默认正文入口、_sidebar.md 和顶部模块导航脚本";
            }
        }

        $sidebar = readDoc($docs . '/_sidebar.md', $errors);
        if ($sidebar !== '') {
            if (preg_match('/\\]\\((?!\\/|https?:|mailto:|#)([^)]+)\\)/u', $sidebar, $match) === 1) {
                $errors[] = "_sidebar.md 必须使用 docsify 根路径链接，发现相对链接: {$match[1]}";
            }
            foreach (['快速开始', '用户教程', '系统功能', '开发指南', '接口参考', '架构设计', '部署运维', '开源协作'] as $section) {
                if (
                    !str_contains($sidebar, "* {$section}")
                    && !str_contains($sidebar, "* **{$section}**")
                ) {
                    $errors[] = "_sidebar.md 缺少主章节: {$section}";
                }
            }
        }

        $index = readDoc($docs . '/index.html', $errors);
        foreach (['homepage: "快速开始/README.md"', 'routerMode: "hash"', 'relativePath: true', 'loadSidebar: true', 'notFoundPage: true', 'pagination:'] as $needle) {
            if ($index !== '' && !str_contains($index, $needle)) {
                $errors[] = "docs/index.html 缺少 docsify 配置: {$needle}";
            }
        }
        if ($index !== '' && str_contains($index, 'loadNavbar: true')) {
            $errors[] = 'docs/index.html 不应启用独立顶部导航，顶部模块导航需要由 devapi-module-nav.js 根据侧边栏生成';
        }
        if ($index !== '' && str_contains($index, 'coverpage: true')) {
            $errors[] = 'docs/index.html 不应启用 coverpage，默认入口需要直接展示文档正文';
        }
        foreach (['"/.*/_sidebar.md": "/_sidebar.md"'] as $needle) {
            if ($index !== '' && !str_contains($index, $needle)) {
                $errors[] = "docs/index.html 缺少 docsify alias 配置: {$needle}";
            }
        }
        foreach (['./assets/devapi-docs.css', './assets/devapi-scale.js', './assets/devapi-module-nav.js', './assets/devapi-sidebar-state.js', './assets/devapi-mermaid.js', './assets/api-tester.css', './assets/api-tester.js'] as $needle) {
            if ($index !== '' && !str_contains($index, $needle)) {
                $errors[] = "docs/index.html 缺少文档前端资源: {$needle}";
            }
        }
        if ($index !== '' && !str_contains($index, 'docsify-pagination@')) {
            $errors[] = 'docs/index.html 缺少 docsify-pagination 插件，无法使用参考 DevAPI 翻页布局';
        }
        if ($index !== '' && !str_contains($index, 'mermaid@')) {
            $errors[] = 'docs/index.html 缺少 Mermaid 渲染库，无法渲染 flowchart LR 图表';
        }

        validateMarkdownLinks($docs, $errors);
        validateApiCases($docs, $errors);
        validateApiRouteCoverage($root, $docs, $errors);

        if ($errors !== []) {
            $this->error('docs:check failed');
            foreach ($errors as $error) {
                $this->error(' - ' . $error);
            }
            exit(1);
        }

        $this->line('docs:check ok');
    }
}

function validateMarkdownLinks(string $docs, array &$errors): void
{
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($docs, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || strtolower($file->getExtension()) !== 'md') {
            continue;
        }

        $path = $file->getPathname();
        $content = (string)file_get_contents($path);
        if (preg_match_all('/(?<!!)\[[^\]]+\]\(([^)]+)\)/u', $content, $matches) !== false) {
            foreach ($matches[1] as $target) {
                $target = trim($target);
                if ($target === '' || str_starts_with($target, '#') || preg_match('/^(https?:|mailto:)/i', $target) === 1) {
                    continue;
                }

                $target = preg_replace('/[?#].*$/', '', $target);
                if ($target === '' || str_ends_with($target, '/')) {
                    continue;
                }

                $base = str_starts_with($target, '/')
                    ? $docs . $target
                    : dirname($path) . '/' . $target;

                $candidate = normalizePath($base);
                $exists = is_file($candidate)
                    || is_file($candidate . '.md')
                    || is_file($candidate . '/README.md');

                if (!$exists) {
                    $relative = str_replace(dirname($docs) . '/', '', $path);
                    $errors[] = "{$relative} 存在无效内部链接: {$target}";
                }
            }
        }
    }
}

function validateApiCases(string $docs, array &$errors): void
{
    $files = glob($docs . '/接口参考/*.md') ?: [];
    $excluded = ['README.md', '在线测试.md', '完整接口清单.md'];

    foreach ($files as $file) {
        $name = basename($file);
        if (in_array($name, $excluded, true)) {
            continue;
        }

        $content = readDoc($file, $errors);
        if ($content === '') {
            continue;
        }

        $relative = str_replace(dirname($docs) . '/', '', $file);
        $endpoints = extractApiEndpoints($content);
        if ($endpoints === []) {
            continue;
        }

        if (!str_contains($content, '<!-- API_CASES_START -->') || !str_contains($content, '<!-- API_CASES_END -->')) {
            $errors[] = "{$relative} 缺少接口案例区块标记 API_CASES_START/API_CASES_END";
            continue;
        }

        foreach ($endpoints as $endpoint) {
            [$method, $path] = $endpoint;
            $marker = sprintf('<!-- API_CASE: %s %s -->', $method, $path);
            $position = strpos($content, $marker);
            if ($position === false) {
                $errors[] = "{$relative} 缺少接口案例: {$method} {$path}";
                continue;
            }

            $nextMarker = strpos($content, '<!-- API_CASE:', $position + strlen($marker));
            $endMarker = strpos($content, '<!-- API_CASES_END -->', $position);
            $blockEnd = $nextMarker === false ? ($endMarker === false ? strlen($content) : $endMarker) : $nextMarker;
            $block = substr($content, $position, $blockEnd - $position);

            $jsoncBlock = extractCodeBlock($block, 'jsonc');
            $testerBlock = extractCodeBlock($block, 'api-test');

            if ($jsoncBlock === null) {
                $errors[] = "{$relative} {$method} {$path} 缺少 jsonc 请求案例";
            }
            if ($testerBlock === null) {
                $errors[] = "{$relative} {$method} {$path} 缺少 api-test 在线调试配置";
            }

            if ($jsoncBlock !== null) {
                foreach (['method', 'path', 'headers', 'query', 'body'] as $field) {
                    if (!str_contains($jsoncBlock, "// {$field}:")) {
                        $errors[] = "{$relative} {$method} {$path} 的 jsonc 案例缺少 {$field} 字段注释";
                    }
                }
            }

            $jsoncCase = decodeJsoncBlock($block, 'jsonc', $relative, $method, $path, $errors);
            if (is_array($jsoncCase)) {
                validateApiCasePayload($jsoncCase, '请求案例', $relative, $method, $path, $errors);
                validateEncryptedPasswordCase($jsoncCase, $relative, $method, $path, $errors);
            }

            $testerCase = decodeJsoncBlock($block, 'api-test', $relative, $method, $path, $errors);
            if (is_array($testerCase)) {
                validateApiCasePayload($testerCase, '在线调试配置', $relative, $method, $path, $errors);
            }
        }
    }
}

/**
 * 密码入口的给人阅读请求案例必须展示最终协议对象，避免文档继续暗示可明文提交密码。
 *
 * @param array<string, mixed> $payload
 */
function validateEncryptedPasswordCase(array $payload, string $relative, string $method, string $path, array &$errors): void
{
    $fields = passwordFieldsForEndpoint($method, $path);
    if ($fields === []) {
        return;
    }

    $body = $payload['body'] ?? null;
    if (!is_array($body)) {
        $errors[] = "{$relative} {$method} {$path} 的请求案例 body 必须包含密码加密对象";
        return;
    }

    foreach ($fields as $field) {
        if (!array_key_exists($field, $body)) {
            $errors[] = "{$relative} {$method} {$path} 的请求案例缺少密码字段 {$field}";
            continue;
        }
        $value = $body[$field];
        if (!is_array($value)) {
            $errors[] = "{$relative} {$method} {$path} 的请求案例 {$field} 必须是 kid/nonce/ciphertext 对象";
            continue;
        }
        foreach (['kid', 'nonce', 'ciphertext'] as $key) {
            if (!array_key_exists($key, $value) || !is_string($value[$key]) || trim($value[$key]) === '') {
                $errors[] = "{$relative} {$method} {$path} 的请求案例 {$field}.{$key} 不能为空";
            }
        }
    }
}

/**
 * @return array<int, string>
 */
function passwordFieldsForEndpoint(string $method, string $path): array
{
    $method = strtoupper($method);
    if ($method === 'POST' && $path === '/system/auth/login') {
        return ['password'];
    }
    if ($method === 'PUT' && $path === '/system/auth/password') {
        return ['old_password', 'new_password'];
    }
    if ($method === 'POST' && $path === '/system/user/create') {
        return ['password'];
    }
    if ($method === 'PUT' && $path === '/system/user/update/{id}') {
        return ['password'];
    }
    if ($method === 'PUT' && $path === '/system/user/reset-password/{id}') {
        return ['password'];
    }

    // Project 前台账号和 System 后台维护 ProjectAccount 时同样必须展示加密密码对象，避免文档误导为明文提交。
    if ($method === 'POST' && $path === '/project/account/auth/login') {
        return ['password'];
    }
    if ($method === 'PUT' && $path === '/project/account/auth/password') {
        return ['old_password', 'new_password'];
    }
    if ($method === 'POST' && in_array($path, ['/project/account/create', '/system/project/account/create'], true)) {
        return ['password'];
    }
    if ($method === 'PUT' && in_array($path, ['/project/account/update/{id}', '/system/project/account/update/{id}'], true)) {
        return ['password'];
    }

    return [];
}

/**
 * 用 Controller 注解作为接口真源，防止新增后端路由后忘记补接口文档。
 */
function validateApiRouteCoverage(string $root, string $docs, array &$errors): void
{
    $controllerEndpoints = extractControllerEndpoints($root);
    $docEndpoints = extractDocApiEndpoints($docs);

    foreach (array_diff_key($controllerEndpoints, $docEndpoints) as $endpoint => $source) {
        $errors[] = "接口文档缺少 Controller 路由: {$endpoint} 来源 {$source}";
    }

    foreach (array_diff_key($docEndpoints, $controllerEndpoints) as $endpoint => $source) {
        $errors[] = "接口文档存在未匹配 Controller 的路由: {$endpoint} 来源 {$source}";
    }
}

/**
 * 提取后端 Controller 的 Mapping 注解，返回 METHOD /path 到文件的映射。
 *
 * @return array<string, string>
 */
function extractControllerEndpoints(string $root): array
{
    $endpoints = [];
    // 插件 Controller 固定放在 src/Controller，接口文档只跟随当前标准目录。
    $files = glob($root . '/plugin/*/src/Controller/*Controller.php') ?: [];

    foreach ($files as $file) {
        $content = (string)file_get_contents($file);
        if (preg_match('/#\[Controller\(prefix:\s*[\'"]([^\'"]+)[\'"]\)\]/', $content, $prefixMatch) !== 1) {
            continue;
        }

        $prefix = '/' . trim($prefixMatch[1], '/');
        if (preg_match_all('/#\[(GetMapping|PostMapping|PutMapping|DeleteMapping|PatchMapping)\(path:\s*[\'"]([^\'"]+)[\'"]\)\]/', $content, $matches, PREG_SET_ORDER) === false) {
            continue;
        }

        foreach ($matches as $match) {
            $method = strtoupper(str_replace('Mapping', '', $match[1]));
            $path = $prefix . '/' . trim($match[2], '/');
            $endpoints["{$method} {$path}"] = str_replace($root . '/', '', $file);
        }
    }

    ksort($endpoints);

    return $endpoints;
}

/**
 * 提取接口参考文档中的接口表格路由，完整接口清单页只是汇总，不参与覆盖率计数。
 *
 * @return array<string, string>
 */
function extractDocApiEndpoints(string $docs): array
{
    $endpoints = [];
    $files = glob($docs . '/接口参考/*.md') ?: [];
    $excluded = ['README.md', '在线测试.md', '完整接口清单.md'];

    foreach ($files as $file) {
        if (in_array(basename($file), $excluded, true)) {
            continue;
        }

        $content = (string)file_get_contents($file);
        foreach (extractApiEndpoints($content) as [$method, $path]) {
            $endpoints["{$method} {$path}"] = str_replace(dirname($docs) . '/', '', $file);
        }
    }

    ksort($endpoints);

    return $endpoints;
}

/**
 * 校验接口案例和在线调试配置的结构，确保页面按钮弹窗能直接读取 method/path/headers/query/body。
 *
 * @param array<string, mixed> $payload
 */
function validateApiCasePayload(array $payload, string $label, string $relative, string $method, string $path, array &$errors): void
{
    foreach (['method', 'path', 'headers', 'query', 'body'] as $field) {
        if (!array_key_exists($field, $payload)) {
            $errors[] = "{$relative} {$method} {$path} 的{$label}缺少 {$field}";
        }
    }

    $caseMethod = strtoupper((string)($payload['method'] ?? ''));
    if ($caseMethod !== $method) {
        $errors[] = "{$relative} {$method} {$path} 的{$label} method 不一致: {$caseMethod}";
    }

    $casePath = (string)($payload['path'] ?? '');
    if (!apiPathMatches($path, $casePath)) {
        $errors[] = "{$relative} {$method} {$path} 的{$label} path 不一致: {$casePath}";
    }

    foreach (['headers', 'query'] as $objectField) {
        if (array_key_exists($objectField, $payload) && !is_array($payload[$objectField])) {
            $errors[] = "{$relative} {$method} {$path} 的{$label} {$objectField} 必须是对象";
        }
    }
}

/**
 * 提取指定语言代码块，避免把 api-test 注释误当成 jsonc 请求案例注释。
 */
function extractCodeBlock(string $block, string $lang): ?string
{
    $pattern = '/```' . preg_quote($lang, '/') . '\b[^\n]*\R(.*?)```/su';
    if (preg_match($pattern, $block, $match) !== 1) {
        return null;
    }

    return $match[1];
}

/**
 * 解析 docsify 代码块中的 JSONC。文档示例允许注释和尾逗号，但必须能被在线调试器还原为 JSON。
 *
 * @return array<string, mixed>|null
 */
function decodeJsoncBlock(string $block, string $lang, string $relative, string $method, string $path, array &$errors): ?array
{
    $code = extractCodeBlock($block, $lang);
    if ($code === null) {
        return null;
    }

    $json = stripJsoncTrailingCommas(stripJsoncComments($code));
    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        $errors[] = "{$relative} {$method} {$path} 的 {$lang} 代码块不是可解析 JSONC: " . json_last_error_msg();
        return null;
    }

    return $decoded;
}

/**
 * 兼容 docs 调试器的 JSONC 注释规则：只移除字符串外的 // 与 /* *\/ 注释。
 */
function stripJsoncComments(string $value): string
{
    $output = '';
    $length = strlen($value);
    $inString = false;
    $quote = '';
    $escaped = false;

    for ($index = 0; $index < $length; $index++) {
        $char = $value[$index];
        $next = $value[$index + 1] ?? '';

        if ($inString) {
            $output .= $char;
            if ($escaped) {
                $escaped = false;
            } elseif ($char === '\\') {
                $escaped = true;
            } elseif ($char === $quote) {
                $inString = false;
                $quote = '';
            }
            continue;
        }

        if ($char === '"' || $char === "'") {
            $inString = true;
            $quote = $char;
            $output .= $char;
            continue;
        }

        if ($char === '/' && $next === '/') {
            while ($index < $length && !in_array($value[$index], ["\n", "\r"], true)) {
                $index++;
            }
            $output .= $value[$index] ?? '';
            continue;
        }

        if ($char === '/' && $next === '*') {
            $index += 2;
            while ($index < $length && !(($value[$index] ?? '') === '*' && ($value[$index + 1] ?? '') === '/')) {
                $index++;
            }
            $index++;
            continue;
        }

        $output .= $char;
    }

    return $output;
}

function stripJsoncTrailingCommas(string $value): string
{
    return (string)preg_replace('/,\s*([}\]])/', '$1', $value);
}

function apiPathMatches(string $expected, string $actual): bool
{
    if ($expected === $actual) {
        return true;
    }

    $parts = preg_split('/(\{[^\/{}]+\})/', $expected, -1, PREG_SPLIT_DELIM_CAPTURE);
    if ($parts === false) {
        return false;
    }

    $pattern = '';
    foreach ($parts as $part) {
        if ($part === '') {
            continue;
        }
        $pattern .= preg_match('/^\{[^\/{}]+\}$/', $part) === 1 ? '[^/]+' : preg_quote($part, '~');
    }

    return preg_match('~^' . $pattern . '$~', $actual) === 1;
}

/**
 * 从接口参考表格中提取真实接口行。
 *
 * @return array<int, array{0: string, 1: string}>
 */
function extractApiEndpoints(string $content): array
{
    $methodsPattern = '(?:GET|POST|PUT|DELETE|PATCH|HEAD)(?:\s*/\s*(?:GET|POST|PUT|DELETE|PATCH|HEAD))*';
    if (preg_match_all('~^\|\s*(' . $methodsPattern . ')\s*\|\s*`([^`]+)`~m', $content, $matches, PREG_SET_ORDER) === false) {
        return [];
    }

    $endpoints = [];
    foreach ($matches as $match) {
        $methods = explode('/', str_replace(' ', '', $match[1]));
        $path = trim($match[2]);
        foreach ($methods as $method) {
            if (!in_array($method, ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'HEAD'], true)) {
                continue;
            }
            $key = "{$method} {$path}";
            $endpoints[$key] = [$method, $path];
        }
    }

    return array_values($endpoints);
}

function normalizePath(string $path): string
{
    $parts = [];
    foreach (explode('/', str_replace('\\', '/', $path)) as $part) {
        if ($part === '' || $part === '.') {
            continue;
        }
        if ($part === '..') {
            array_pop($parts);
            continue;
        }
        $parts[] = $part;
    }

    return '/' . implode('/', $parts);
}

function readDoc(string $file, array &$errors): string
{
    if (!is_file($file)) {
        $root = defined('BASE_PATH') ? (string)constant('BASE_PATH') : dirname(__DIR__, 3);
        $errors[] = '无法读取文件: ' . str_replace($root . '/', '', $file);
        return '';
    }

    return (string)file_get_contents($file);
}
