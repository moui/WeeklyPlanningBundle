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

use App\Entity\User;
use App\WorkingTime\WorkingTimeService;
use KimaiPlugin\WeeklyPlanningBundle\Model\Hours;

/**
 * Weekly capacity = expected working hours for that Monday–Sunday week, minus
 * absences (AbsencesBundle, or any plugin that adds working-time day addons).
 *
 * Users with a working-time contract get Kimai's own WorkingTimeService
 * numbers (the "Working times" page: locked months, work start / last
 * working day). Users whose contract mode is "none" — where Kimai would
 * report zero — fall back to the sum of their work_monday…work_sunday
 * preferences, so they can still be planned.
 */
final class CapacityCalculator
{
    private const DAY_PREFERENCES = [
        'work_monday', 'work_tuesday', 'work_wednesday', 'work_thursday',
        'work_friday', 'work_saturday', 'work_sunday',
    ];

    /** @var array<string, float> */
    private array $cache = [];

    public function __construct(private readonly WorkingTimeService $workingTimes)
    {
    }

    public function forWeek(User $user, \DateTimeImmutable $monday): float
    {
        $key = $user->getId() . '@' . $monday->format('Y-m-d');

        return $this->cache[$key] ??= $this->calculate($user, $monday);
    }

    private function calculate(User $user, \DateTimeImmutable $monday): float
    {
        if (!$user->hasWorkHourConfiguration()) {
            $seconds = 0;
            foreach (self::DAY_PREFERENCES as $name) {
                $seconds += (int) $user->getPreferenceValue($name, 0, false);
            }

            return Hours::fromSeconds($seconds);
        }

        $timezone = new \DateTimeZone($user->getTimezone());
        $fromKey = $monday->format('Y-m-d');
        $toKey = $monday->modify('+6 days')->format('Y-m-d');
        $until = new \DateTimeImmutable($toKey . ' 23:59:59', $timezone);
        $seconds = 0;

        for ($year = (int) substr($fromKey, 0, 4); $year <= (int) substr($toKey, 0, 4); $year++) {
            $yearDate = new \DateTimeImmutable($year . '-01-01 00:00:00', $timezone);
            foreach ($this->workingTimes->getYear($user, $yearDate, $until)->getMonths() as $month) {
                foreach ($month->getDays() as $day) {
                    $dayKey = $day->getDay()->format('Y-m-d');
                    $workingTime = $day->getWorkingTime();
                    if ($workingTime !== null && $dayKey >= $fromKey && $dayKey <= $toKey) {
                        // Holidays already lower the expected time; absences
                        // (vacation, medical leave, …) are day addons credited
                        // as time, so they are not available for planning.
                        $absent = array_sum(array_map(static fn ($addon): int => $addon->getDuration(), $day->getAddons()));
                        $seconds += max(0, $workingTime->getExpectedTime() - $absent);
                    }
                }
            }
        }

        return Hours::fromSeconds($seconds);
    }
}
