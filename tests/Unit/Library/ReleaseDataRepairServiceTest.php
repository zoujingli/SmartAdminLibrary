<?php

declare(strict_types=1);

namespace Tests\Unit\Library;

use Library\Interfaces\ReleaseDataRepairInterface;
use Library\Service\ReleaseDataRepairService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/** @internal */
#[CoversClass(ReleaseDataRepairService::class)]
final class ReleaseDataRepairServiceTest extends TestCase
{
    protected function setUp(): void
    {
        ReleaseDataRepairFirstFixture::$calls = [];
        ReleaseDataRepairSecondFixture::$calls = [];
    }

    public function testRunsRegisteredRepairsByStableCodeAndAggregatesBlockingItems(): void
    {
        $service = new ReleaseDataRepairService([
            'fixture.20.second' => ReleaseDataRepairSecondFixture::class,
            'fixture.10.first' => ReleaseDataRepairFirstFixture::class,
        ]);
        $preview = $service->preview();

        self::assertTrue($preview['required']);
        self::assertFalse($preview['ready']);
        self::assertSame(['fixture.10.first', 'fixture.20.second'], array_column($preview['items'], 'code'));
        self::assertSame('fixture.20.second', $preview['blocking'][0]['code']);
        self::assertSame(['preview'], ReleaseDataRepairFirstFixture::$calls);
        self::assertSame(['preview'], ReleaseDataRepairSecondFixture::$calls);

        $applied = $service->repair();
        self::assertSame(['fixture.10.first', 'fixture.20.second'], array_column($applied['items'], 'code'));
        self::assertSame(['preview', 'repair'], ReleaseDataRepairFirstFixture::$calls);
        self::assertSame(['preview', 'repair'], ReleaseDataRepairSecondFixture::$calls);
    }

    public function testRejectsRegistrationCodeMismatch(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('发布数据修复器代码不一致');
        (new ReleaseDataRepairService(['fixture.wrong' => ReleaseDataRepairFirstFixture::class]))->preview();
    }
}

final class ReleaseDataRepairFirstFixture implements ReleaseDataRepairInterface
{
    /** @var list<string> */
    public static array $calls = [];

    public function code(): string
    {
        return 'fixture.10.first';
    }

    public function preview(): array
    {
        self::$calls[] = 'preview';
        return ['required' => true, 'blocking' => [], 'summary' => ['rows' => 1]];
    }

    public function repair(): array
    {
        self::$calls[] = 'repair';
        return ['rows' => 1];
    }
}

final class ReleaseDataRepairSecondFixture implements ReleaseDataRepairInterface
{
    /** @var list<string> */
    public static array $calls = [];

    public function code(): string
    {
        return 'fixture.20.second';
    }

    public function preview(): array
    {
        self::$calls[] = 'preview';
        return ['required' => false, 'blocking' => [['message' => 'fixture blocked']], 'summary' => []];
    }

    public function repair(): array
    {
        self::$calls[] = 'repair';
        return ['rows' => 0];
    }
}
