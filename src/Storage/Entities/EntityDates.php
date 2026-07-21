<?php

declare(strict_types=1);

namespace AINewsAutomator\Storage\Entities;

/**
 * Shared MySQL DATETIME <-> DateTimeImmutable conversion, used by every
 * entity's fromRow()/toRow(). Centralized so date handling is identical
 * and correct (UTC) across all ten entities, rather than each DTO
 * reimplementing its own date parsing.
 */
final class EntityDates
{
    private const FORMAT = 'Y-m-d H:i:s';

    public static function toMysql(\DateTimeImmutable $date): string
    {
        return $date->format(self::FORMAT);
    }

    public static function nullableToMysql(?\DateTimeImmutable $date): ?string
    {
        return $date?->format(self::FORMAT);
    }

    public static function fromMysql(string $value): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat(self::FORMAT, $value);

        return $date !== false ? $date : new \DateTimeImmutable('@0');
    }

    public static function nullableFromMysql(mixed $value): ?\DateTimeImmutable
    {
        if ($value === null || $value === '' || $value === '0000-00-00 00:00:00') {
            return null;
        }

        return self::fromMysql((string) $value);
    }

    public static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
