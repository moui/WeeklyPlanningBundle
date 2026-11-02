<?php

declare(strict_types=1);

/*
 * This file is part of the "WeeklyPlanningBundle" for Kimai.
 * Copyright (c) 2026 Mauricio Barrera (https://github.com/moui)
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\WeeklyPlanningBundle\Controller;

use App\Controller\AbstractController;
use App\Entity\User;
use App\Utils\PageSetup;
use KimaiPlugin\WeeklyPlanningBundle\Model\Hours;
use KimaiPlugin\WeeklyPlanningBundle\Model\PlannerScope;
use KimaiPlugin\WeeklyPlanningBundle\Model\ProjectOption;
use KimaiPlugin\WeeklyPlanningBundle\Service\BudgetForecast;
use KimaiPlugin\WeeklyPlanningBundle\Service\Csv;
use KimaiPlugin\WeeklyPlanningBundle\Service\DateFormatter;
use KimaiPlugin\WeeklyPlanningBundle\Service\PlanningService;
use KimaiPlugin\WeeklyPlanningBundle\Service\ScopeResolver;
use KimaiPlugin\WeeklyPlanningBundle\Service\Week;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Weekly assignments: read-only rollups of one week's saved plans.
 *
 * @phpstan-import-type Budget from BudgetForecast
 * @phpstan-type DetailRow array{user_id: int, project_id: int, person: string, customer: string, project: string, hours: float, capacity: float, notes: string}
 * @phpstan-type RollupRow array{label: string, customer: string, hours: string, project_id: int|null}
 */
#[Route(path: '/weekly-planning/assignments')]
#[IsGranted('weekly_planning')]
final class AssignmentsController extends AbstractController
{
    private const VIEWS = [
        'person' => 'weekly_planning.view.person',
        'customer' => 'weekly_planning.view.customer',
        'project' => 'weekly_planning.view.project',
        'detail' => 'weekly_planning.view.detail',
    ];

    public function __construct(
        private readonly ScopeResolver $scopes,
        private readonly PlanningService $planning,
        private readonly BudgetForecast $forecast,
        private readonly DateFormatter $dates,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(path: '', name: 'weekly_planning_assignments', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $planner = $this->getCurrentUser();
        $scope = $this->scopes->resolve($planner);
        $timezone = new \DateTimeZone($planner->getTimezone());
        $monday = Week::parse($request->query->get('week'), $timezone) ?? Week::current($timezone);
        $view = (string) $request->query->get('view', 'person');
        $view = \array_key_exists($view, self::VIEWS) ? $view : 'person';
        [$detail, $capacities] = $this->detail($scope, $monday);

        $page = new PageSetup('weekly_planning.title.assignments');
        $page->setActionName('weekly_planning_assignments');

        return $this->render('@WeeklyPlanning/assignments.html.twig', [
            'page_setup' => $page,
            'scope' => $scope,
            'week' => $monday->format('Y-m-d'),
            'week_label' => $this->dates->week($monday),
            'prev_week' => $monday->modify('-7 days')->format('Y-m-d'),
            'next_week' => $monday->modify('+7 days')->format('Y-m-d'),
            'status' => PlanningController::STATUS_LABELS[$this->planning->findPeriod($monday)?->getStatus() ?? 'draft'],
            'views' => self::VIEWS,
            'view' => $view,
            'detail' => $detail,
            'summary' => $this->summary($detail, $capacities),
            'by_person' => $this->byPerson($detail, $capacities),
            'by_customer' => $this->rollup($detail, 'customer'),
            'by_project' => $this->withBudgets(
                $this->rollup($detail, 'project'),
                $this->forecast->forProjects(array_values(array_unique(array_column($detail, 'project_id'))), new \DateTimeImmutable('today', $timezone), $monday)
            ),
        ]);
    }

    #[Route(path: '/export', name: 'weekly_planning_assignments_export', methods: ['GET'])]
    public function export(Request $request): Response
    {
        $planner = $this->getCurrentUser();
        $scope = $this->scopes->resolve($planner);
        $timezone = new \DateTimeZone($planner->getTimezone());
        $monday = Week::parse($request->query->get('week'), $timezone) ?? Week::current($timezone);
        [$detail] = $this->detail($scope, $monday);

        return Csv::response(
            'weekly_assignments_' . $monday->format('Y-m-d') . '.csv',
            array_map(fn (string $key): string => $this->translator->trans($key), [
                'weekly_planning.person', 'customer', 'project', 'weekly_planning.assigned_hours', 'weekly_planning.capacity', 'weekly_planning.notes',
            ]),
            array_map(static fn (array $r): array => [$r['person'], $r['customer'], $r['project'], Hours::plain($r['hours']), Hours::plain($r['capacity']), $r['notes']], $detail)
        );
    }

    /**
     * @return array{0: list<DetailRow>, 1: array<int, array{person: string, capacity: float}>}
     */
    private function detail(PlannerScope $scope, \DateTimeImmutable $monday): array
    {
        $period = $this->planning->findPeriod($monday);
        if ($period === null) {
            return [[], []];
        }

        $rows = [];
        $capacities = [];
        foreach ($this->planning->findPlansForPeriod($period) as $plan) {
            $userId = (int) $plan->getUser()->getId();
            if (!$scope->isAdmin() && !$scope->allowsUser($userId)) {
                continue;
            }
            foreach ($plan->getAllocations() as $allocation) {
                $project = $allocation->getProject();
                if (!$scope->allowsRow($userId, (int) $project->getId())) {
                    continue;
                }
                $capacities[$userId] = ['person' => $plan->getUser()->getDisplayName(), 'capacity' => $plan->getCapacityHours()];
                $rows[] = [
                    'user_id' => $userId,
                    'project_id' => (int) $project->getId(),
                    'person' => $plan->getUser()->getDisplayName(),
                    'customer' => (string) $project->getCustomer()?->getName(),
                    'project' => ProjectOption::label((string) $project->getName(), (string) $project->getCustomer()?->getName()),
                    'hours' => $allocation->getHours(),
                    'capacity' => $plan->getCapacityHours(),
                    'notes' => (string) $allocation->getNotes(),
                ];
            }
        }

        usort($rows, static fn (array $a, array $b): int => [$a['person'], $a['project']] <=> [$b['person'], $b['project']]);

        return [$rows, $capacities];
    }

    /**
     * @param list<DetailRow> $detail
     * @param array<int, array{person: string, capacity: float}> $capacities
     * @return array<string, string|int>
     */
    private function summary(array $detail, array $capacities): array
    {
        $assigned = Hours::round(array_sum(array_column($detail, 'hours')));
        $capacity = Hours::round(array_sum(array_column($capacities, 'capacity')));

        return [
            'assigned' => Hours::format($assigned),
            'capacity' => Hours::format($capacity),
            'utilization' => Hours::percent(Hours::safePercent($assigned, $capacity) ?? 0.0),
            'people' => \count($capacities),
            'customers' => \count(array_unique(array_column($detail, 'customer'))),
            'projects' => \count(array_unique(array_column($detail, 'project'))),
            'remaining' => Hours::format(Hours::round($capacity - $assigned)),
        ];
    }

    /**
     * @param list<DetailRow> $detail
     * @param array<int, array{person: string, capacity: float}> $capacities
     * @return list<array<string, string|bool>>
     */
    private function byPerson(array $detail, array $capacities): array
    {
        $assigned = [];
        foreach ($detail as $row) {
            $assigned[$row['user_id']] = ($assigned[$row['user_id']] ?? 0.0) + $row['hours'];
        }
        arsort($assigned);

        $rows = [];
        foreach ($assigned as $userId => $hours) {
            $capacity = $capacities[$userId]['capacity'];
            $rows[] = [
                'person' => $capacities[$userId]['person'],
                'assigned' => Hours::format(Hours::round($hours)),
                'capacity' => Hours::format($capacity),
                'remaining' => Hours::format(Hours::round($capacity - $hours)),
                'utilization' => Hours::percent(Hours::safePercent($hours, $capacity) ?? 0.0),
                'over' => $hours > $capacity,
            ];
        }

        return $rows;
    }

    /**
     * @param list<DetailRow> $detail
     * @param 'customer'|'project' $key
     * @return list<RollupRow>
     */
    private function rollup(array $detail, string $key): array
    {
        $totals = [];
        $customers = [];
        $ids = [];
        foreach ($detail as $row) {
            $totals[$row[$key]] = ($totals[$row[$key]] ?? 0.0) + $row['hours'];
            $customers[$row[$key]] = $row['customer'];
            $ids[$row[$key]] = $key === 'project' ? $row['project_id'] : null;
        }
        arsort($totals);

        $rows = [];
        foreach ($totals as $label => $hours) {
            $rows[] = ['label' => (string) $label, 'customer' => $customers[$label], 'hours' => Hours::format(Hours::round($hours)), 'project_id' => $ids[$label]];
        }

        return $rows;
    }

    /**
     * Adds the budget columns to the per-project rollup. "Planned (everyone)"
     * counts every plan of the week, not only the viewer's teams, because the
     * budget belongs to the whole project.
     *
     * @param list<RollupRow> $rows
     * @param array<int, Budget> $budgets
     * @return list<array<string, mixed>>
     */
    private function withBudgets(array $rows, array $budgets): array
    {
        foreach ($rows as $index => $row) {
            $budget = $row['project_id'] !== null ? ($budgets[$row['project_id']] ?? null) : null;
            $rows[$index]['budget'] = $budget === null ? null : [
                'open' => Hours::format($budget['open']),
                'planned_all' => Hours::format($budget['planned']),
                'rate' => Hours::format($budget['rate']),
                'exhaustion' => $budget['exhaustion'] ?? '—',
                'risk' => $budget['risk'],
                'risk_label' => $budget['risk_label'],
                'over' => $budget['planned'] > $budget['open'],
            ];
        }

        return $rows;
    }

    private function getCurrentUser(): User
    {
        return $this->getUser();
    }
}
