<?php

declare(strict_types=1);
/**
 * This file is part of SmartAdmin.
 *
 * @contact Anyon <zoujingli@qq.com>
 * @license https://github.com/zoujingli/SmartAdmin/blob/master/LICENSE
 * @document https://zoujingli.github.io/SmartAdmin
 */

namespace Tests\Unit\Library\Logger;

use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\ServerRequest;
use Hyperf\Context\ApplicationContext;
use Hyperf\Context\Context;
use Hyperf\HttpMessage\Stream\SwooleFileStream;
use Hyperf\HttpMessage\Stream\SwooleStream;
use Hyperf\Logger\LoggerFactory;
use Library\Auth\Token;
use Library\Exception\ErrorResponseException;
use Library\Logger\RequestIdHolder;
use Library\Logger\RequestLogRecorder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

/**
 * @internal
 */
#[CoversClass(RequestLogRecorder::class)]
final class RequestLogRecorderTest extends TestCase
{
    protected function tearDown(): void
    {
        $this->clearRequestLogContext();
    }

    public function testBeginAndLogResponseWriteRequestAndResponse(): void
    {
        $logger = new class extends AbstractLogger {
            /**
             * @var array<int, array{level:mixed,message:string,context:array<string, mixed>}>
             */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = [
                    'level' => $level,
                    'message' => (string)$message,
                    'context' => $context,
                ];
            }
        };

        $recorder = $this->makeRecorder($logger);
        $request = (new ServerRequest('POST', 'https://admin.example.com/system/login?page=1', [
            'Content-Type' => 'application/json',
            'X-Forwarded-For' => '10.0.0.8',
        ], json_encode([
            'username' => 'admin',
            'password' => 'secret',
        ], JSON_THROW_ON_ERROR)))->withQueryParams(['page' => '1']);
        $response = new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'code' => 200,
            'data' => ['token' => 'secret'],
        ], JSON_THROW_ON_ERROR));

        $context = $recorder->begin($request);
        $recorder->logResponse($request, $response, $context['start_time'], $context['request_id']);

        $this->assertCount(2, $logger->records);
        $this->assertSame('onRequest', $logger->records[0]['message']);
        $this->assertSame('POST', $logger->records[0]['context']['method']);
        $this->assertSame('/system/login', $logger->records[0]['context']['path']);
        $this->assertSame('10.0.0.8 - 内网', $logger->records[0]['context']['client_ip']);
        $this->assertSame('***', $logger->records[0]['context']['body']['password']);
        $this->assertArrayNotHasKey('request_id', $logger->records[0]['context']);
        $this->assertArrayNotHasKey('uri', $logger->records[0]['context']);
        $this->assertArrayNotHasKey('ip', $logger->records[0]['context']);
        $this->assertArrayNotHasKey('ip_location', $logger->records[0]['context']);
        $this->assertSame('onResponse', $logger->records[1]['message']);
        $this->assertSame('info', $logger->records[1]['level']);
        $this->assertSame(200, $logger->records[1]['context']['http_status']);
        $this->assertIsString($logger->records[1]['context']['duration']);
        $this->assertIsString($logger->records[1]['context']['memory_usage']);
        $this->assertSame('***', $logger->records[1]['context']['body']['data']['token']);
        $this->assertArrayNotHasKey('request_id', $logger->records[1]['context']);
        $this->assertArrayNotHasKey('method', $logger->records[1]['context']);
        $this->assertArrayNotHasKey('path', $logger->records[1]['context']);
        $this->assertArrayNotHasKey('status', $logger->records[1]['context']);
        $this->assertArrayNotHasKey('uri', $logger->records[1]['context']);
        $this->assertArrayNotHasKey('ip', $logger->records[1]['context']);
        $this->assertArrayNotHasKey('ip_location', $logger->records[1]['context']);
        $this->assertArrayNotHasKey('body_code', $logger->records[1]['context']);
        $this->assertArrayNotHasKey('body_info', $logger->records[1]['context']);
        $this->assertArrayNotHasKey('result', $logger->records[1]['context']);
        $this->assertArrayNotHasKey('size', $logger->records[1]['context']);
        $this->assertArrayNotHasKey('exception', $logger->records[1]['context']);
    }

    public function testResponseLogIsWrittenOnce(): void
    {
        $logger = new class extends AbstractLogger {
            /**
             * @var array<int, string>
             */
            public array $messages = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->messages[] = (string)$message;
            }
        };

        $recorder = $this->makeRecorder($logger);
        $request = new ServerRequest('GET', 'https://admin.example.com/system/user');
        $response = new Response(200);

        $context = $recorder->begin($request);
        $recorder->logResponse($request, $response, $context['start_time'], $context['request_id']);
        $recorder->logResponse($request, $response, $context['start_time'], $context['request_id']);

        $this->assertSame(['onRequest', 'onResponse'], $logger->messages);
    }

    public function testJsonBodyKeepsStructureWhenPreviewIsTrimmed(): void
    {
        $logger = new class extends AbstractLogger {
            /**
             * @var array<int, array<string, mixed>>
             */
            public array $contexts = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->contexts[] = $context;
            }
        };

        $recorder = $this->makeRecorder($logger);
        $request = new ServerRequest('POST', 'https://admin.example.com/system/data', [
            'Content-Type' => 'application/json',
        ], json_encode([
            'token' => 'secret',
            'items' => range(1, 12),
            'content' => str_repeat('a', 2500),
        ], JSON_THROW_ON_ERROR));
        $response = new Response(200, [], json_encode([
            'token' => 'secret',
            'data' => [
                'items' => range(1, 12),
                'content' => str_repeat('b', 2500),
            ],
        ], JSON_THROW_ON_ERROR));

        $context = $recorder->begin($request);
        $recorder->logResponse($request, $response, $context['start_time'], $context['request_id']);

        $this->assertArrayNotHasKey('raw', $logger->contexts[0]['body']);
        $this->assertArrayNotHasKey('raw', $logger->contexts[1]['body']);
        $this->assertIsArray($logger->contexts[0]['body']);
        $this->assertIsArray($logger->contexts[1]['body']);
        $this->assertSame('***', $logger->contexts[0]['body']['token']);
        $this->assertSame('***', $logger->contexts[1]['body']['token']);
        $this->assertSame(['...(2)' => '太多内容了'], $logger->contexts[0]['body']['items'][10]);
        $this->assertSame(['...(2)' => '太多内容了'], $logger->contexts[1]['body']['data']['items'][10]);
        $this->assertStringEndsWith('...', $logger->contexts[0]['body']['content']);
        $this->assertStringEndsWith('...', $logger->contexts[1]['body']['data']['content']);
        $this->assertStringNotContainsString('secret', json_encode($logger->contexts[0]['body'], JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('secret', json_encode($logger->contexts[1]['body'], JSON_THROW_ON_ERROR));
    }

    public function testResponseLogMasksSensitiveUrlParameters(): void
    {
        $logger = new class extends AbstractLogger {
            /**
             * @var array<int, array<string, mixed>>
             */
            public array $contexts = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->contexts[] = $context;
            }
        };

        $recorder = $this->makeRecorder($logger);
        $request = new ServerRequest('POST', 'https://admin.example.com/project/account/auth/transfer-ticket');
        $response = new Response(200, [], json_encode([
            'code' => 200,
            'data' => [
                'login_url' => '/project/auth/transfer?ticket=plain-ticket&redirect=%2Fproject%2Ftask%3Fid%3D24',
            ],
        ], JSON_THROW_ON_ERROR));

        $context = $recorder->begin($request);
        $recorder->logResponse($request, $response, $context['start_time'], $context['request_id']);

        $body = $logger->contexts[1]['body'];
        $this->assertSame('/project/auth/transfer?ticket=***&redirect=%2Fproject%2Ftask%3Fid%3D24', $body['data']['login_url']);
        $this->assertStringNotContainsString('plain-ticket', json_encode($body, JSON_THROW_ON_ERROR));
    }

    public function testNonJsonBodyUsesStringPreviewWithoutRawWrapper(): void
    {
        $logger = new class extends AbstractLogger {
            /**
             * @var array<int, array<string, mixed>>
             */
            public array $contexts = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->contexts[] = $context;
            }
        };

        $recorder = $this->makeRecorder($logger);
        $request = new ServerRequest('POST', 'https://admin.example.com/system/data', [], 'token=secret&data=' . str_repeat('a', 2100));

        $recorder->begin($request);

        $this->assertIsString($logger->contexts[0]['body']);
        $this->assertStringEndsNotWith('...', $logger->contexts[0]['body']);
        $this->assertStringContainsString('token=***', $logger->contexts[0]['body']);
        $this->assertStringNotContainsString('secret', $logger->contexts[0]['body']);
    }

    public function testResponseLogReadsSwooleStreamBody(): void
    {
        $logger = new class extends AbstractLogger {
            /**
             * @var array<int, array{level:mixed,message:string,context:array<string, mixed>}>
             */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = [
                    'level' => $level,
                    'message' => (string)$message,
                    'context' => $context,
                ];
            }
        };

        $recorder = $this->makeRecorder($logger);
        $request = new ServerRequest('GET', 'https://admin.example.com/system/auth/ui-meta');
        $response = new Response(200, [], new SwooleStream(json_encode([
            'code' => 200,
            'info' => '获取成功',
            'data' => ['menus' => [], 'permissions' => []],
        ], JSON_THROW_ON_ERROR)));

        $context = $recorder->begin($request);
        $recorder->logResponse($request, $response, $context['start_time'], $context['request_id']);

        $this->assertSame('info', $logger->records[1]['level']);
        $this->assertSame(200, $logger->records[1]['context']['body']['code']);
        $this->assertSame('获取成功', $logger->records[1]['context']['body']['info']);
    }

    public function testBusinessErrorResponseLogsErrorWithoutExceptionRecord(): void
    {
        $logger = new class extends AbstractLogger {
            /**
             * @var array<int, array{level:mixed,message:string,context:array<string, mixed>}>
             */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = [
                    'level' => $level,
                    'message' => (string)$message,
                    'context' => $context,
                ];
            }
        };

        $recorder = $this->makeRecorder($logger);
        $request = new ServerRequest('PUT', 'https://admin.example.com/system/user/15');
        $exception = new ErrorResponseException('用户不存在');
        $response = $exception->withResponse(new Response());

        $recorder->logResponse($request, $response, null, null, $exception);

        $this->assertCount(1, $logger->records);
        $this->assertSame('error', $logger->records[0]['level']);
        $this->assertSame('onResponse', $logger->records[0]['message']);
        $this->assertSame(500, $logger->records[0]['context']['body']['code']);
        $this->assertSame('用户不存在', $logger->records[0]['context']['body']['info']);
        $this->assertArrayNotHasKey('exception', $logger->records[0]['context']);
    }

    public function testNonStandardHttpStatusWithoutBusinessCodeLogsError(): void
    {
        $logger = new class extends AbstractLogger {
            /**
             * @var array<int, array{level:mixed,message:string,context:array<string, mixed>}>
             */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = [
                    'level' => $level,
                    'message' => (string)$message,
                    'context' => $context,
                ];
            }
        };

        $recorder = $this->makeRecorder($logger);
        $request = new ServerRequest('GET', 'https://admin.example.com/upstream/error');
        $response = new Response(503, [], 'Service Unavailable');

        $recorder->logResponse($request, $response);

        $this->assertSame('error', $logger->records[0]['level']);
        $this->assertSame('onResponse', $logger->records[0]['message']);
        $this->assertSame(503, $logger->records[0]['context']['http_status']);
        $this->assertSame('Service Unavailable', $logger->records[0]['context']['body']);
    }

    public function testBodyOverTenKbIsSuppressed(): void
    {
        $logger = new class extends AbstractLogger {
            /**
             * @var array<int, array<string, mixed>>
             */
            public array $contexts = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->contexts[] = $context;
            }
        };

        $recorder = $this->makeRecorder($logger);
        $request = new ServerRequest('POST', 'https://admin.example.com/system/data', [
            'Content-Type' => 'application/json',
        ], json_encode([
            'token' => 'secret',
            'content' => str_repeat('a', 11000),
        ], JSON_THROW_ON_ERROR));
        $response = new Response(200, [], new SwooleStream(json_encode([
            'code' => 200,
            'info' => '获取成功',
            'data' => str_repeat('b', 11000),
        ], JSON_THROW_ON_ERROR)));

        $context = $recorder->begin($request);
        $recorder->logResponse($request, $response, $context['start_time'], $context['request_id']);

        $suppressed = ['...(>10KB)' => '不输出日志'];
        $this->assertSame($suppressed, $logger->contexts[0]['body']);
        $this->assertSame($suppressed, $logger->contexts[1]['body']);
        $this->assertStringNotContainsString('secret', json_encode($logger->contexts, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString(str_repeat('a', 100), json_encode($logger->contexts, JSON_THROW_ON_ERROR));
    }

    public function testSwooleFileStreamResponseBodyIsNotRead(): void
    {
        $logger = new class extends AbstractLogger {
            /**
             * @var array<int, array{level:mixed,message:string,context:array<string, mixed>}>
             */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = [
                    'level' => $level,
                    'message' => (string)$message,
                    'context' => $context,
                ];
            }
        };
        $file = tempnam(sys_get_temp_dir(), 'request-log-file-');
        self::assertIsString($file);
        file_put_contents($file, 'download content');

        try {
            $recorder = $this->makeRecorder($logger);
            $request = new ServerRequest('GET', 'https://admin.example.com/system/download');
            $response = new Response(200, [], new SwooleFileStream($file));

            $recorder->logResponse($request, $response);

            $this->assertSame('info', $logger->records[0]['level']);
            $this->assertSame('onResponse', $logger->records[0]['message']);
            $this->assertNull($logger->records[0]['context']['body']);
        } finally {
            @unlink($file);
        }
    }

    public function testThrowingStreamDoesNotBreakResponseLog(): void
    {
        $logger = new class extends AbstractLogger {
            /**
             * @var array<int, array{level:mixed,message:string,context:array<string, mixed>}>
             */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = [
                    'level' => $level,
                    'message' => (string)$message,
                    'context' => $context,
                ];
            }
        };
        $stream = new class implements StreamInterface {
            public function __toString(): string
            {
                return '';
            }

            public function close(): void {}

            public function detach()
            {
                return null;
            }

            public function getSize(): ?int
            {
                return null;
            }

            public function tell(): int
            {
                throw new \RuntimeException('tell failed');
            }

            public function eof(): bool
            {
                return false;
            }

            public function isSeekable(): bool
            {
                throw new \RuntimeException('seekable failed');
            }

            public function seek(int $offset, int $whence = SEEK_SET): void
            {
                throw new \RuntimeException('seek failed');
            }

            public function rewind(): void
            {
                throw new \RuntimeException('rewind failed');
            }

            public function isWritable(): bool
            {
                return false;
            }

            public function write(string $string): int
            {
                throw new \RuntimeException('write failed');
            }

            public function isReadable(): bool
            {
                return true;
            }

            public function read(int $length): string
            {
                throw new \RuntimeException('read failed');
            }

            public function getContents(): string
            {
                throw new \RuntimeException('contents failed');
            }

            public function getMetadata(?string $key = null)
            {
                return null;
            }
        };

        $recorder = $this->makeRecorder($logger);
        $request = new ServerRequest('GET', 'https://admin.example.com/system/download');

        $recorder->logResponse($request, new Response(200, [], $stream));

        $this->assertSame('info', $logger->records[0]['level']);
        $this->assertNull($logger->records[0]['context']['body']);
    }

    public function testBinaryRequestBodyIsSkipped(): void
    {
        $logger = new class extends AbstractLogger {
            /**
             * @var array<int, array<string, mixed>>
             */
            public array $contexts = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->contexts[] = $context;
            }
        };
        $recorder = $this->makeRecorder($logger);

        foreach ([
            'multipart/form-data; boundary=----smartadmin',
            'application/octet-stream',
        ] as $contentType) {
            $request = new ServerRequest('POST', 'https://admin.example.com/system/upload', [
                'Content-Type' => $contentType,
            ], 'token=secret&file-content');

            $recorder->begin($request);
            $this->assertNull($logger->contexts[array_key_last($logger->contexts)]['body']);
            $this->clearRequestLogContext();
        }
    }

    public function testHugeQueryArrayIsTrimmed(): void
    {
        $logger = new class extends AbstractLogger {
            /**
             * @var array<int, array<string, mixed>>
             */
            public array $contexts = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->contexts[] = $context;
            }
        };

        $recorder = $this->makeRecorder($logger);
        $request = (new ServerRequest('GET', 'https://admin.example.com/system/data'))
            ->withQueryParams([
                'token' => 'secret',
                'items' => range(1, 12),
            ]);

        $recorder->begin($request);

        $this->assertSame('***', $logger->contexts[0]['query']['token']);
        $this->assertSame(['...(2)' => '太多内容了'], $logger->contexts[0]['query']['items'][10]);
    }

    public function testSuppressedBaseResponseExceptionBodyStillUsesBusinessCodeLevel(): void
    {
        $logger = new class extends AbstractLogger {
            /**
             * @var array<int, array{level:mixed,message:string,context:array<string, mixed>}>
             */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = [
                    'level' => $level,
                    'message' => (string)$message,
                    'context' => $context,
                ];
            }
        };

        $recorder = $this->makeRecorder($logger);
        $request = new ServerRequest('GET', 'https://admin.example.com/system/user/15');
        $exception = new ErrorResponseException('用户不存在');
        $response = (new Response(200))->withBody(new SwooleStream(str_repeat('x', 11000)));

        $recorder->logResponse($request, $response, null, null, $exception);

        $this->assertSame('error', $logger->records[0]['level']);
        $this->assertSame(['...(>10KB)' => '不输出日志'], $logger->records[0]['context']['body']);
        $this->assertSame(['onResponse'], array_column($logger->records, 'message'));
    }

    public function testRealThrowableKeepsErrorLevelWhenBodyIsSuppressed(): void
    {
        $logger = new class extends AbstractLogger {
            /**
             * @var array<int, array{level:mixed,message:string,context:array<string, mixed>}>
             */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = [
                    'level' => $level,
                    'message' => (string)$message,
                    'context' => $context,
                ];
            }
        };

        $recorder = $this->makeRecorder($logger);
        $request = new ServerRequest('GET', 'https://admin.example.com/system/error');
        $throwable = new \RuntimeException('系统异常');
        $response = (new Response(200))->withBody(new SwooleStream(str_repeat('x', 11000)));

        $recorder->logResponse($request, $response, null, null, $throwable);

        $this->assertSame(['onResponse', 'exception'], array_column($logger->records, 'message'));
        $this->assertSame('error', $logger->records[0]['level']);
        $this->assertSame(['...(>10KB)' => '不输出日志'], $logger->records[0]['context']['body']);
        $this->assertSame(\RuntimeException::class, $logger->records[1]['context']['exception']['class']);
    }

    public function testFallbackExceptionLogIsNotBlockedWhenLoggerWriteFails(): void
    {
        $originalContainer = $this->getApplicationContainer();
        $errorLog = tempnam(sys_get_temp_dir(), 'request-log-error-');
        self::assertIsString($errorLog);
        $originalErrorLog = ini_get('error_log');
        $throwingLogger = new class extends AbstractLogger {
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                throw new \RuntimeException('logger failed');
            }
        };
        $capturedLogger = new class extends AbstractLogger {
            /**
             * @var array<int, array{level:mixed,message:string,context:array<string, mixed>}>
             */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = [
                    'level' => $level,
                    'message' => (string)$message,
                    'context' => $context,
                ];
            }
        };

        try {
            ini_set('error_log', $errorLog);
            ApplicationContext::setContainer($this->makeLoggerContainer($throwingLogger));
            RequestLogRecorder::fallbackLogException(new \RuntimeException('first failure'));

            ApplicationContext::setContainer($this->makeLoggerContainer($capturedLogger));
            RequestLogRecorder::fallbackLogException(new \RuntimeException('second failure'));

            $this->assertSame(['exception'], array_column($capturedLogger->records, 'message'));
            $this->assertStringContainsString('first failure', (string)file_get_contents($errorLog));
        } finally {
            ini_set('error_log', $originalErrorLog === false ? '' : $originalErrorLog);
            $this->restoreApplicationContainer($originalContainer);
            @unlink($errorLog);
        }
    }

    public function testFallbackExceptionLogHandlesInvalidUtf8Message(): void
    {
        $originalContainer = $this->getApplicationContainer();
        $errorLog = tempnam(sys_get_temp_dir(), 'request-log-error-');
        self::assertIsString($errorLog);
        $originalErrorLog = ini_get('error_log');
        $throwingLogger = new class extends AbstractLogger {
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                throw new \RuntimeException('logger failed');
            }
        };

        try {
            ini_set('error_log', $errorLog);
            ApplicationContext::setContainer($this->makeLoggerContainer($throwingLogger));
            RequestLogRecorder::fallbackLogException(new \RuntimeException("invalid \xC3\x28 message"));

            $this->assertStringContainsString('"exception"', (string)file_get_contents($errorLog));
        } finally {
            ini_set('error_log', $originalErrorLog === false ? '' : $originalErrorLog);
            $this->restoreApplicationContainer($originalContainer);
            @unlink($errorLog);
        }
    }

    public function testRepeatedResponseCanStillWriteExceptionOnce(): void
    {
        $logger = new class extends AbstractLogger {
            /**
             * @var array<int, array{level:mixed,message:string,context:array<string, mixed>}>
             */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = [
                    'level' => $level,
                    'message' => (string)$message,
                    'context' => $context,
                ];
            }
        };

        $recorder = $this->makeRecorder($logger);
        $request = new ServerRequest('GET', 'https://admin.example.com/system/user');
        $response = new Response(200, [], json_encode(['code' => 200], JSON_THROW_ON_ERROR));
        $throwable = new \RuntimeException('late failure');

        $recorder->logResponse($request, $response);
        $recorder->logResponse($request, $response, null, null, $throwable);
        $recorder->logResponse($request, $response, null, null, $throwable);

        $this->assertSame(['onResponse', 'exception'], array_column($logger->records, 'message'));
        $this->assertSame(\RuntimeException::class, $logger->records[1]['context']['exception']['class']);
    }

    public function testSystemThrowableLogsErrorResponseAndExceptionRecord(): void
    {
        $logger = new class extends AbstractLogger {
            /**
             * @var array<int, array{level:mixed,message:string,context:array<string, mixed>}>
             */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = [
                    'level' => $level,
                    'message' => (string)$message,
                    'context' => $context,
                ];
            }
        };

        $recorder = $this->makeRecorder($logger);
        $request = new ServerRequest('GET', 'https://admin.example.com/system/auth/ui-meta');
        $throwable = new \RuntimeException('系统异常');
        $response = new Response(200, [], new SwooleStream(json_encode([
            'code' => 500,
            'info' => '系统异常',
            'data' => null,
            'path' => '/system/auth/ui-meta',
        ], JSON_THROW_ON_ERROR)));

        $recorder->logResponse($request, $response, null, null, $throwable);

        $this->assertCount(2, $logger->records);
        $this->assertSame('error', $logger->records[0]['level']);
        $this->assertSame('onResponse', $logger->records[0]['message']);
        $this->assertArrayNotHasKey('exception', $logger->records[0]['context']);
        $this->assertSame('error', $logger->records[1]['level']);
        $this->assertSame('exception', $logger->records[1]['message']);
        $this->assertSame(\RuntimeException::class, $logger->records[1]['context']['exception']['class']);
        $this->assertSame('系统异常', $logger->records[1]['context']['exception']['message']);
        $this->assertArrayHasKey('trace', $logger->records[1]['context']['exception']);
    }

    public function testStandardResponseExceptionWithPreviousLogsPreviousThrowable(): void
    {
        $logger = new class extends AbstractLogger {
            /**
             * @var array<int, array{level:mixed,message:string,context:array<string, mixed>}>
             */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = [
                    'level' => $level,
                    'message' => (string)$message,
                    'context' => $context,
                ];
            }
        };

        $recorder = $this->makeRecorder($logger);
        $request = new ServerRequest('GET', 'https://admin.example.com/system/auth/ui-meta');
        $previous = new \RuntimeException('底层异常');
        $exception = new ErrorResponseException('系统异常', null, 500, $previous);
        $response = $exception->withResponse(new Response());

        $recorder->logResponse($request, $response, null, null, $exception);

        $this->assertCount(2, $logger->records);
        $this->assertSame('error', $logger->records[0]['level']);
        $this->assertSame('exception', $logger->records[1]['message']);
        $this->assertSame(\RuntimeException::class, $logger->records[1]['context']['exception']['class']);
        $this->assertSame('底层异常', $logger->records[1]['context']['exception']['message']);
    }

    public function testJsonPreviewMasksPasswordObject(): void
    {
        $logger = new class extends AbstractLogger {
            /**
             * @var array<int, array<string, mixed>>
             */
            public array $contexts = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->contexts[] = $context;
            }
        };

        $recorder = $this->makeRecorder($logger);
        $body = json_encode([
            'username' => 'admin',
            'password' => [
                'kid' => 'runtime-key-id',
                'nonce' => 'server-issued-nonce',
                'ciphertext' => 'base64-rsa-oaep-ciphertext',
            ],
            'remark' => str_repeat('a', 520),
        ], JSON_THROW_ON_ERROR);
        $request = new ServerRequest('POST', 'https://admin.example.com/system/auth/login', [
            'Content-Type' => 'application/json',
        ], $body);

        $recorder->begin($request);

        $this->assertSame('***', $logger->contexts[0]['body']['password']);
        $this->assertStringEndsWith('...', $logger->contexts[0]['body']['remark']);
        $this->assertStringNotContainsString('server-issued-nonce', json_encode($logger->contexts[0]['body'], JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('base64-rsa-oaep-ciphertext', json_encode($logger->contexts[0]['body'], JSON_THROW_ON_ERROR));
    }

    public function testTokenHeaderCanResolveRequestUserSummary(): void
    {
        $logger = new class extends AbstractLogger {
            /**
             * @var array<int, array<string, mixed>>
             */
            public array $contexts = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->contexts[] = $context;
            }
        };
        $token = $this->createMock(Token::class);
        $token->expects($this->once())->method('getParserData')->with('header-token')->willReturn([
            'uid' => 12,
            'class' => 'System\\Model\\SystemUser',
        ]);

        $recorder = $this->makeRecorder($logger, $token);
        $request = new ServerRequest('GET', 'https://admin.example.com/system/auth/ui-meta', [
            'token' => 'header-token',
        ]);

        $recorder->begin($request);

        $this->assertSame([
            'id' => 12,
            'user_model' => 'System\\Model\\SystemUser',
            'authenticated' => true,
        ], $logger->contexts[0]['user']);
    }

    private function makeRecorder(AbstractLogger $logger, ?Token $token = null): RequestLogRecorder
    {
        $factory = $this->loggerFactory($logger);

        return new RequestLogRecorder($factory, $token ?? $this->createStub(Token::class));
    }

    private function clearRequestLogContext(): void
    {
        foreach ([
            RequestIdHolder::REQUEST_ID,
            '__library.request_log.start_time',
            '__library.request_log.request_id',
            '__library.request_log.request_logged',
            '__library.request_log.response_logged',
            '__library.request_log.exception_logged',
        ] as $key) {
            Context::destroy($key);
        }
    }

    /**
     * @return null|ContainerInterface
     */
    private function getApplicationContainer(): ?ContainerInterface
    {
        $property = new \ReflectionProperty(ApplicationContext::class, 'container');
        $container = $property->getValue();

        return $container instanceof ContainerInterface ? $container : null;
    }

    private function restoreApplicationContainer(?ContainerInterface $container): void
    {
        $property = new \ReflectionProperty(ApplicationContext::class, 'container');
        $property->setValue(null, $container);
        $this->clearRequestLogContext();
    }

    private function makeLoggerContainer(LoggerInterface $logger): ContainerInterface
    {
        $factory = $this->loggerFactory($logger);

        return new class($factory) implements ContainerInterface {
            public function __construct(
                private readonly LoggerFactory $factory,
            ) {}

            public function get(string $id)
            {
                if ($id === LoggerFactory::class) {
                    return $this->factory;
                }

                throw new \RuntimeException("Unsupported service {$id}");
            }

            public function has(string $id): bool
            {
                return $id === LoggerFactory::class;
            }
        };
    }

    private function loggerFactory(LoggerInterface $logger): LoggerFactory
    {
        $factory = $this->createStub(LoggerFactory::class);
        $factory->method('get')->willReturnCallback(static fn (string $name): LoggerInterface => match ($name) {
            'log' => $logger,
            default => throw new \RuntimeException(sprintf('Unexpected logger channel [%s].', $name)),
        });

        return $factory;
    }
}
