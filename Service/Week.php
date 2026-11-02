<?php

declare(strict_types=1);

/*
 * This file is part of the "WeeklyPlanningBundle" for Kimai.
 * Copyright (c) 2026 Mauricio Barrera (https://github.com/moui)
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\WeeklyPlanningBundle\Service;

/**
 * Monday-based week helpers. Kept explicit (ISO day number arithmetic) rather
 * than relying on relative formats like "monday this week".
 */
final class Week
{
    public static function monday(\DateTimeInterface $date): \DateTimeImmutable
    {
        $day = \DateTimeImmutable::createFromInterface($date)->setTime(0, 0);

        return $day->modify('-' . ((int) $day->format('N') - 1) . ' days');
    }

    /**
     * Parses a Y-m-d string to the Monday of its week; null when malformed.
     */
    public static function parse(?string $value, \DateTimeZone $timezone): ?\DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, $timezone);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            return null;
        }

        return self::monday($date);
    }

    public static function current(\DateTimeZone $timezone): \DateTimeImmutable
    {
        return self::monday(new \DateTimeImmutable('today', $timezone));
    }
}
