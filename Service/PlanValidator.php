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

use KimaiPlugin\WeeklyPlanningBundle\Model\Hours;
use KimaiPlugin\WeeklyPlanningBundle\Model\PlannerScope;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * Normalises the posted editor rows and runs the save-time checks.
 *
 * Every row must have a project when it has hours or notes, a positive number
 * of hours, and a project inside the planner's scope; a project may appear
 * only once. Empty rows are ignored. The plan as a whole must not be empty,
 * needs a capacity above zero, and must not exceed it.
 */
final class PlanValidator
{
    /**
     * @param array<mixed> $posted raw rows[i][project|hours|notes] from the request
     * @return array{rows: list<array{project: int, hours: float, notes: string}>, errors: list<TranslatableMessage>, draft: list<array{project: int|null, hours: string, notes: string}>}
     */
    public function normalize(array $posted, PlannerScope $scope): array
    {
        $rows = [];
        $errors = [];
        $draft = [];
        $seen = [];
        $number = 0;

        foreach ($posted as $item) {
            if (!\is_array($item)) {
                continue;
            }
            $number++;
            $projectRaw = trim(self::text($item['project'] ?? ''));
            $hoursRaw = trim(str_replace(',', '.', self::text($item['hours'] ?? '')));
            $notes = trim(self::text($item['notes'] ?? ''));
            $projectId = ctype_digit($projectRaw) ? (int) $projectRaw : null;
            $draft[] = ['project' => $projectId, 'hours' => $hoursRaw, 'notes' => $notes];

            if ($hoursRaw !== '' && !is_numeric($hoursRaw)) {
                $errors[] = new TranslatableMessage('weekly_planning.error.invalid_hours', ['%row%' => (string) $number]);
                continue;
            }
            $hours = Hours::round((float) ($hoursRaw === '' ? 0 : $hoursRaw));

            if ($projectRaw === '') {
                if ($hours > 0 || $notes !== '') {
                    $errors[] = new TranslatableMessage('weekly_planning.error.missing_project', ['%row%' => (string) $number]);
                }
                continue;
            }

            if ($projectId === null || !$scope->allowsProject($projectId)) {
                $errors[] = new TranslatableMessage('weekly_planning.error.project_out_of_scope', ['%row%' => (string) $number]);
                continue;
            }

            if ($hours <= 0) {
                $errors[] = new TranslatableMessage('weekly_planning.error.hours_not_positive', ['%row%' => (string) $number]);
                continue;
            }

            if (isset($seen[$projectId])) {
                $errors[] = new TranslatableMessage('weekly_planning.error.duplicate_project', ['%row%' => (string) $number]);
                continue;
            }

            $seen[$projectId] = true;
            $rows[] = ['project' => $projectId, 'hours' => $hours, 'notes' => mb_substr($notes, 0, 2000)];
        }

        return ['rows' => $rows, 'errors' => $errors, 'draft' => $draft];
    }

    /**
     * @param list<array{project: int, hours: float, notes: string}> $rows
     * @return list<TranslatableMessage>
     */
    public function checkTotals(array $rows, float $capacity): array
    {
        $errors = [];
        $total = self::total($rows);

        if (\count($rows) === 0) {
            $errors[] = new TranslatableMessage('weekly_planning.error.empty_plan');
        } elseif ($capacity <= 0) {
            $errors[] = new TranslatableMessage('weekly_planning.error.zero_capacity');
        } elseif ($total > $capacity) {
            $errors[] = new TranslatableMessage('weekly_planning.error.over_capacity');
        }

        return $errors;
    }

    /**
     * @param list<array{project: int|null, hours: float|string, notes: string}> $rows
     */
    public static function total(array $rows): float
    {
        $total = 0.0;
        foreach ($rows as $row) {
            $total += is_numeric($row['hours']) ? (float) $row['hours'] : 0.0;
        }

        return Hours::round($total);
    }

    /**
     * A posted form value as text; anything that is not a scalar counts as empty.
     */
    private static function text(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }
}
