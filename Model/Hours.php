<?php

declare(strict_types=1);

/*
 * This file is part of the "WeeklyPlanningBundle" for Kimai.
 * Copyright (c) 2026 Mauricio Barrera (https://github.com/moui)
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\WeeklyPlanningBundle\Model;

/**
 * Planning works in decimal hours (the unit people type into the editor),
 * rounded to two decimals.
 *
 * Formatting lives in PHP on purpose: Twig macros imported in a block are out
 * of scope inside `{% embed %}`, and with strict_variables off that fails
 * silently with empty cells.
 */
final class Hours
{
    public static function round(float $hours): float
    {
        return round($hours, 2);
    }

    public static function fromSeconds(int $seconds): float
    {
        return round($seconds / 3600, 2);
    }

    public static function format(?float $hours): string
    {
        if ($hours === null) {
            return '—';
        }

        return number_format($hours, 2, '.', '') . ' h';
    }

    /**
     * Plain number for CSV cells and form inputs.
     */
    public static function plain(float $hours): string
    {
        return number_format($hours, 2, '.', '');
    }

    public static function percent(?float $value): string
    {
        return $value === null ? '—' : number_format($value, 2, '.', '') . '%';
    }

    /**
     * part / total * 100, or null when the total is zero (no "infinite" deviation).
     */
    public static function safePercent(float $part, float $total): ?float
    {
        if (abs($total) < 0.00001) {
            return null;
        }

        return round($part / $total * 100, 2);
    }
}
