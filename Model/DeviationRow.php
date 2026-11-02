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
 * Planned vs. actual for one (week, person, project).
 */
final class DeviationRow
{
    /**
     * Translation keys of the statuses.
     */
    public const STATUS_LABELS = [
        'matched' => 'weekly_planning.deviation_status.matched',
        'over' => 'weekly_planning.deviation_status.over',
        'under' => 'weekly_planning.deviation_status.under',
        'unplanned' => 'weekly_planning.deviation_status.unplanned',
        'not_executed' => 'weekly_planning.deviation_status.not_executed',
    ];

    public readonly float $deviation;
    public readonly ?float $percent;
    public readonly string $status;

    public function __construct(
        public readonly string $week,
        public readonly int $userId,
        public readonly string $userName,
        public readonly int $projectId,
        public readonly string $projectLabel,
        public readonly int $customerId,
        public readonly string $customerName,
        public readonly float $assigned,
        public readonly float $actual,
    ) {
        $this->deviation = Hours::round($actual - $assigned);
        $this->percent = Hours::safePercent($this->deviation, $assigned);
        $this->status = self::statusFor($assigned, $actual);
    }

    /**
     * Status of a row, on values rounded to two decimals so that tiny
     * floating-point differences never turn "matched" into over / under:
     * nothing planned but hours recorded is "unplanned", planned but nothing
     * recorded is "not executed", otherwise matched / over / under.
     */
    public static function statusFor(float $assigned, float $actual): string
    {
        $assigned = Hours::round($assigned);
        $actual = Hours::round($actual);

        return match (true) {
            $assigned === 0.0 && $actual > 0 => 'unplanned',
            $assigned > 0 && $actual === 0.0 => 'not_executed',
            $actual === $assigned => 'matched',
            $actual > $assigned => 'over',
            default => 'under',
        };
    }

    /**
     * Translation key of the status.
     */
    public function getStatusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    public function getStatusColor(): string
    {
        return match ($this->status) {
            'matched' => 'green',
            'over' => 'red',
            'under' => 'secondary',
            'unplanned' => 'orange',
            // not_executed is the extreme of "under": grey, like the widget.
            default => 'secondary',
        };
    }

    public function getWeekStart(): \DateTimeImmutable
    {
        return new \DateTimeImmutable($this->week);
    }

    public function getAssignedFormatted(): string
    {
        return Hours::format($this->assigned);
    }

    public function getActualFormatted(): string
    {
        return Hours::format($this->actual);
    }

    public function getDeviationFormatted(): string
    {
        return Hours::format($this->deviation);
    }

    public function getPercentFormatted(): string
    {
        return Hours::percent($this->percent);
    }
}
