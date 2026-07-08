<?php

declare(strict_types=1);
/**
 * This file is part of SmartAdmin.
 *
 * @contact Anyon <zoujingli@qq.com>
 * @license https://github.com/zoujingli/SmartAdmin/blob/master/LICENSE
 * @document https://zoujingli.github.io/SmartAdmin
 */

namespace Tests\Unit\Library\Support;

use Library\Support\SensitiveDataFilter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(SensitiveDataFilter::class)]
final class SensitiveDataFilterTest extends TestCase
{
    public function testApplyMasksDefaultAndDotPathFields(): void
    {
        $filtered = SensitiveDataFilter::apply([
            'token' => 'token-value',
            'drivers' => [
                'oss' => [
                    'access_id' => 'keep',
                    'access_secret' => 'mask-me',
                ],
            ],
        ], ['drivers.oss.access_secret']);

        $this->assertSame('***', $filtered['token']);
        $this->assertSame('keep', $filtered['drivers']['oss']['access_id']);
        $this->assertSame('***', $filtered['drivers']['oss']['access_secret']);
    }

    public function testApplyTruncatesLongStrings(): void
    {
        $filtered = SensitiveDataFilter::apply(['raw' => 'abcdef'], [], 3);

        $this->assertSame('abc...', $filtered['raw']);
    }

    public function testApplyMasksSensitiveStringValuesWithoutSensitiveKeys(): void
    {
        $jwt = 'eyJhbGciOiJIUzI1NiJ9.eyJ1aWQiOjF9.signaturepart';
        $filtered = SensitiveDataFilter::apply([
            'data' => $jwt,
            'link' => '/customer/evaluate/c0dec0dec0dec0dec0dec0dec0dec0dec0dec0dec0dec0de?redirect=/customer/share/abcdefabcdefabcdefabcdefabcdefabcdef',
            'profile' => [
                'wechat' => 'wx-secret',
                'note' => '联系微信 wx-secret',
            ],
        ]);

        $this->assertSame('***', $filtered['data']);
        $this->assertSame('/customer/evaluate/[public-token]?redirect=/customer/share/[public-token]', $filtered['link']);
        $this->assertSame('***', $filtered['profile']['wechat']);
        $this->assertSame('联系微信 wx-secret', $filtered['profile']['note']);
    }
}
