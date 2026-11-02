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
use KimaiPlugin\WeeklyPlanningBundle\Model\DeviationRow;
use KimaiPlugin\WeeklyPlanningBundle\Model\Hours;
use KimaiPlugin\WeeklyPlanningBundle\Model\PlannerScope;
use KimaiPlugin\WeeklyPlanningBundle\Model\ProjectOption;

/**
 * Plan vs. actual, computed on every request from the timesheets. Nothing is
 * stored, so editing a timesheet is reflected immediately and there is no
 * import or recalculation step to forget.
 */
final class DeviationService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly ActualHours $actuals,
        private readonly NameLookup $names,
    ) {
    }

    /**
     * @return list<DeviationRow>
     */
    public function forRange(PlannerScope $scope, \DateTimeImmutable $firstMonday, \DateTimeImmutable $lastMonday): array
    {
        /** @var array<string, array{week: string, user: int, project: int, assigned: float, actual_seconds: int}> $cells */
        $cells = [];

        foreach ($this->planned($firstMonday, $lastMonday) as $row) {
            $key = $row['week'] . '|' . $row['user_id'] . '|' . $row['project_id'];
            $cells[$key] = ['week' => $row['week'], 'user' => $row['user_id'], 'project' => $row['project_id'], 'assigned' => $row['hours'], 'actual_seconds' => 0];
        }

        foreach ($this->actuals->byWeekUserProject($firstMonday, $lastMonday) as $row) {
            $key = $row['week'] . '|' . $row['user_id'] . '|' . $row['project_id'];
            if (!isset($cells[$key])) {
                // Unplanned work is only listed for people the planner can see.
                if (!$scope->allowsUser($row['user_id'])) {
                    continue;
                }
                $cells[$key] = ['week' => $row['week'], 'user' => $row['user_id'], 'project' => $row['project_id'], 'assigned' => 0.0, 'actual_seconds' => 0];
            }
            $cells[$key]['actual_seconds'] += $row['seconds'];
        }

        $cells = array_filter($cells, static fn (array $cell): bool => $scope->allowsRow($cell['user'], $cell['project']));

        $userNames = $this->names->users(array_map(static fn (array $c): int => $c['user'], array_values($cells)));
        $projects = $this->names->projects(array_map(static fn (array $c): int => $c['project'], array_values($cells)));

        $rows = [];
        foreach ($cells as $cell) {
            $project = $projects[$cell['project']] ?? ['name' => '#' . $cell['project'], 'customer_id' => 0, 'customer_name' => '?'];
            $rows[] = new DeviationRow(
                $cell['week'],
                $cell['user'],
                $userNames[$cell['user']] ?? '#' . $cell['user'],
                $cell['project'],
                ProjectOption::label($project['name'], $project['customer_name']),
                $project['customer_id'],
                $project['customer_name'],
                Hours::round($cell['assigned']),
                Hours::fromSeconds($cell['actual_seconds']),
            );
        }

        usort($rows, static fn (DeviationRow $a, DeviationRow $b): int => [$a->week, $a->userName, $a->projectLabel] <=> [$b->week, $b->userName, $b->projectLabel]);

        return $rows;
    }

    /**
     * @return list<array{week: string, user_id: int, project_id: int, hours: float}>
     */
    private function planned(\DateTimeImmutable $firstMonday, \DateTimeImmutable $lastMonday): array
    {
        /** @var list<array{week: string, user_id: int|string, project_id: int|string, hours: float|string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT pp.week_start AS week, pl.user_id, a.project_id, SUM(a.hours) AS hours
                FROM kimai2_weekly_planning_allocations a
                INNER JOIN kimai2_weekly_planning_plans pl ON pl.id = a.plan_id
                INNER JOIN kimai2_weekly_planning_periods pp ON pp.id = pl.period_id
                WHERE pp.week_start BETWEEN :from AND :to
                GROUP BY pp.week_start, pl.user_id, a.project_id
                SQL,
            ['from' => $firstMonday->format('Y-m-d'), 'to' => $lastMonday->format('Y-m-d')]
        );

        return array_map(static fn (array $row): array => [
            'week' => (string) $row['week'],
            'user_id' => (int) $row['user_id'],
            'project_id' => (int) $row['project_id'],
            'hours' => (float) $row['hours'],
        ], $rows);
    }
}
