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

use App\Entity\Project;
use App\Project\ProjectStatisticService;
use App\Repository\ProjectRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use KimaiPlugin\WeeklyPlanningBundle\Model\Hours;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Budget status and exhaustion forecast for projects with a time budget,
 * shown where hours are being assigned.
 *
 * Spent / open hours come from Kimai's own budget statistics, so they match the
 * project's budget bar in Kimai (which counts billable time only, and handles
 * monthly budgets). Run-rate = average billable hours per week over the last
 * RUN_RATE_WEEKS complete weeks (weeks without hours count as zero), so one
 * unusually busy or quiet week does not swing the forecast; weeks left =
 * open / run-rate. Traffic light: red when exhausted or under 4 weeks left,
 * yellow up to 8, green otherwise.
 *
 * @phpstan-type Budget array{
 *     id: int, monthly: bool, budget: float, spent: float, open: float, usage: ?float,
 *     rate: float, weeks: ?float, exhaustion: ?string, risk: string, risk_label: string,
 *     planned: float, planned_by_user: array<int, float>
 * }
 */
final class BudgetForecast
{
    public const RUN_RATE_WEEKS = 4;

    private const RISK_LABELS = [
        'red' => 'weekly_planning.risk.red',
        'yellow' => 'weekly_planning.risk.yellow',
        'green' => 'weekly_planning.risk.green',
        'idle' => 'weekly_planning.risk.idle',
    ];

    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly ProjectStatisticService $statistics,
        private readonly Connection $connection,
        private readonly DateFormatter $dates,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * @param list<int> $projectIds
     * @param \DateTimeImmutable $today the viewer's "now", used as the budget cut-off
     * @param \DateTimeImmutable $planningMonday the week being planned, for "planned" hours
     * @return array<int, Budget>
     */
    public function forProjects(array $projectIds, \DateTimeImmutable $today, \DateTimeImmutable $planningMonday): array
    {
        $projects = array_values(array_filter(
            $projectIds === [] ? [] : $this->projects->findByIds(array_values(array_unique($projectIds))),
            static fn (Project $project): bool => $project->getTimeBudget() > 0
        ));
        if ($projects === []) {
            return [];
        }

        $ids = array_map(static fn (Project $project): int => (int) $project->getId(), $projects);
        $models = $this->statistics->getBudgetStatisticModelForProjects($projects, $today);
        $currentMonday = Week::monday($today);
        $rates = $this->weeklyRunRates($ids, $currentMonday);
        $planned = $this->plannedHours($ids, $planningMonday);

        $result = [];
        foreach ($projects as $project) {
            $id = (int) $project->getId();
            $model = $models[$id];
            $budget = Hours::fromSeconds($model->getTimeBudget());
            $spent = Hours::fromSeconds($model->getTimeBudgetSpent());
            $open = Hours::round($budget - $spent);
            $rate = $rates[$id] ?? 0.0;
            $monthly = $project->isMonthlyBudget();

            if ($open <= 0) {
                $weeks = 0.0;
            } elseif ($rate > 0) {
                $weeks = round($open / $rate, 1);
            } else {
                $weeks = null;
            }

            // A monthly budget resets every month, so "runs out on" only makes
            // sense within the current month; beyond ten years it is noise.
            $exhaustion = null;
            if ($weeks !== null && $weeks <= 520) {
                $date = $currentMonday->modify('+' . (int) round($weeks * 7) . ' days');
                if (!$monthly || $date->format('Y-m') === $today->format('Y-m')) {
                    $exhaustion = $this->dates->date($date);
                }
            }

            $risk = match (true) {
                $open <= 0 => 'red',
                $weeks === null => 'idle',
                $weeks < 4 => 'red',
                $weeks <= 8 => 'yellow',
                default => 'green',
            };

            $result[$id] = [
                'id' => $id,
                'monthly' => $monthly,
                'budget' => $budget,
                'spent' => $spent,
                'open' => max($open, 0.0),
                'usage' => Hours::safePercent($spent, $budget),
                'rate' => $rate,
                'weeks' => $weeks,
                'exhaustion' => $exhaustion,
                'risk' => $risk,
                'risk_label' => $this->translator->trans($open <= 0 ? 'weekly_planning.risk.exhausted' : self::RISK_LABELS[$risk]),
                'planned' => Hours::round(array_sum($planned[$id] ?? [])),
                'planned_by_user' => $planned[$id] ?? [],
            ];
        }

        return $result;
    }

    /**
     * Average billable hours per week over the complete weeks before $currentMonday.
     *
     * @param list<int> $ids
     * @return array<int, float>
     */
    private function weeklyRunRates(array $ids, \DateTimeImmutable $currentMonday): array
    {
        /** @var list<array{project_id: int|string, seconds: int|string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT t.project_id, SUM(t.duration) AS seconds
                FROM kimai2_timesheet t
                WHERE t.project_id IN (:ids)
                  AND t.billable = 1
                  AND t.end_time IS NOT NULL
                  AND t.date_tz BETWEEN :from AND :to
                GROUP BY t.project_id
                SQL,
            [
                'ids' => $ids,
                'from' => $currentMonday->modify('-' . (self::RUN_RATE_WEEKS * 7) . ' days')->format('Y-m-d'),
                'to' => $currentMonday->modify('-1 day')->format('Y-m-d'),
            ],
            ['ids' => ArrayParameterType::INTEGER]
        );

        $rates = [];
        foreach ($rows as $row) {
            $rates[(int) $row['project_id']] = round((int) $row['seconds'] / 3600 / self::RUN_RATE_WEEKS, 2);
        }

        return $rates;
    }

    /**
     * Saved planned hours for the week, per project and person, across every
     * plan — a budget is shared by the whole project, not only one team.
     *
     * @param list<int> $ids
     * @return array<int, array<int, float>>
     */
    private function plannedHours(array $ids, \DateTimeImmutable $monday): array
    {
        /** @var list<array{project_id: int|string, user_id: int|string, hours: float|string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT a.project_id, pl.user_id, SUM(a.hours) AS hours
                FROM kimai2_weekly_planning_allocations a
                INNER JOIN kimai2_weekly_planning_plans pl ON pl.id = a.plan_id
                INNER JOIN kimai2_weekly_planning_periods pe ON pe.id = pl.period_id
                WHERE pe.week_start = :week
                  AND a.project_id IN (:ids)
                GROUP BY a.project_id, pl.user_id
                SQL,
            ['week' => $monday->format('Y-m-d'), 'ids' => $ids],
            ['ids' => ArrayParameterType::INTEGER]
        );

        $planned = [];
        foreach ($rows as $row) {
            $planned[(int) $row['project_id']][(int) $row['user_id']] = Hours::round((float) $row['hours']);
        }

        return $planned;
    }
}
