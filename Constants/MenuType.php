<?php

declare(strict_types=1);
/**
 * This file is part of SmartAdmin.
 *
 * @contact Anyon <zoujingli@qq.com>
 * @license https://github.com/zoujingli/SmartAdmin/blob/master/LICENSE
 * @document https://zoujingli.github.io/SmartAdmin
 */

namespace Library\Constants;

final class MenuType
{
    public const PATH = 'D';

    public const MENU = 'M';

    public const BUTTON = 'B';

    public const LINK = 'L';

    public const IFRAME = 'I';

    /**
     * @var array<string, string>
     */
    private const LABELS = [
        self::PATH => '目录',
        self::MENU => '菜单',
        self::BUTTON => '按钮',
        self::LINK => '外链',
        self::IFRAME => '内嵌页',
    ];

    /**
     * @var array<string, int>
     */
    private const FORM_VALUES = [
        self::PATH => 1,
        self::MENU => 2,
        self::BUTTON => 3,
        self::LINK => 4,
        self::IFRAME => 5,
    ];

    /**
     * @var array<string, array<int, string>>
     */
    private const ALIASES = [
        self::PATH => [self::PATH, '1', 'PATH', 'DIRECTORY'],
        self::MENU => [self::MENU, '2', 'MENU'],
        self::BUTTON => [self::BUTTON, '3', 'BUTTON'],
        self::LINK => [self::LINK, '4', 'LINK', 'EXTERNAL'],
        self::IFRAME => [self::IFRAME, '5', 'IFRAME', 'EMBED'],
    ];

    /**
     * @var array<int, string>
     */
    private const CONTAINER_TYPES = [self::PATH, self::MENU];

    public static function getText(mixed $type): string
    {
        $normalized = self::resolveAlias($type);

        return $normalized === null ? '未知类型' : self::LABELS[$normalized];
    }

    /**
     * @return array<string, string>
     */
    public static function getAll(): array
    {
        return self::LABELS;
    }

    public static function isValid(mixed $type): bool
    {
        return self::resolveAlias($type) !== null;
    }

    public static function normalize(mixed $type): string
    {
        return self::resolveAlias($type) ?? self::MENU;
    }

    public static function resolve(mixed $type, string $component = '', string $link = '', string $iframeSrc = ''): string
    {
        $normalized = self::normalize($type);

        if ($normalized === self::MENU && $component === 'BasicLayout') {
            return self::PATH;
        }

        if ($link !== '') {
            return self::LINK;
        }

        if ($iframeSrc !== '') {
            return self::IFRAME;
        }

        return $normalized;
    }

    public static function toFormValue(mixed $type): int
    {
        return self::FORM_VALUES[self::normalize($type)] ?? self::FORM_VALUES[self::MENU];
    }

    public static function isContainer(mixed $type): bool
    {
        return in_array(self::normalize($type), self::CONTAINER_TYPES, true);
    }

    /**
     * @return array<int, string>
     */
    public static function getContainerTypes(): array
    {
        return self::CONTAINER_TYPES;
    }

    /**
     * @return array<int, int|string>
     */
    public static function getQueryValues(mixed $type): array
    {
        $normalized = self::resolveAlias($type);
        if ($normalized === null) {
            return [(string)$type];
        }

        $formValue = self::FORM_VALUES[$normalized];

        return [$normalized, $formValue, (string)$formValue];
    }

    /**
     * @return array<int, int|string>
     */
    public static function getContainerQueryValues(): array
    {
        $values = [];

        foreach (self::CONTAINER_TYPES as $type) {
            $values = array_merge($values, self::getQueryValues($type));
        }

        return $values;
    }

    private static function resolveAlias(mixed $type): ?string
    {
        $value = strtoupper(trim((string)$type));
        if ($value === '') {
            return null;
        }

        foreach (self::ALIASES as $normalized => $aliases) {
            if (in_array($value, $aliases, true)) {
                return $normalized;
            }
        }

        return null;
    }
}
