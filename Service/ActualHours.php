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

use Doctrine\DBAL\Connection;

/**
 * Hours actually recorded in Kimai, read live, so there is nothing to keep
 * in sync.
 *
 * Only finished timesheets count (end_time IS NOT NULL), and days are the
 * timesheet's own local date (date_tz), so a late-evening entry never slips
 * into the next week because of UTC.
 */
final class ActualHours
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Seconds per project for one user in [from, to].
     *
     * @return array<int, int> project id => seconds, largest first
     */
    public function byProjectForUser(int $userId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        /** @var list<array{project_id: int|string, seconds: int|string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT t.project_id, SUM(t.duration) AS seconds
                FROM kimai2_timesheet t
                WHERE t.user = :user
                  AND t.end_time IS NOT NULL
                  AND t.date_tz BETWEEN :from AND :to
                GROUP BY t.project_id
                ORDER BY seconds DESC
                SQL,
            ['user' => $userId, 'from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')]
        );

        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['project_id']] = (int) $row['seconds'];
        }

        return $result;
    }

    /**
     * Seconds per (week, user, project) for every week whose Monday is in
     * [firstMonday, lastMonday].
     *
     * @return list<array{week: string, user_id: int, project_id: int, seconds: int}>
     */
    public function byWeekUserProject(\DateTimeImmutable $firstMonday, \DateTimeImmutable $lastMonday): array
    {
        /** @var list<array{week: string, user_id: int|string, project_id: int|string, seconds: int|string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT DATE_SUB(t.date_tz, INTERVAL WEEKDAY(t.date_tz) DAY) AS week,
                       t.user AS user_id, t.project_id, SUM(t.duration) AS seconds
                FROM kimai2_timesheet t
                WHERE t.end_time IS NOT NULL
                  AND t.date_tz BETWEEN :from AND :to
                GROUP BY week, t.user, t.project_id
                SQL,
            ['from' => $firstMonday->format('Y-m-d'), 'to' => $lastMonday->modify('+6 days')->format('Y-m-d')]
        );

        return array_map(static fn (array $row): array => [
            'week' => (string) $row['week'],
            'user_id' => (int) $row['user_id'],
            'project_id' => (int) $row['project_id'],
            'seconds' => (int) $row['seconds'],
        ], $rows);
    }
}
