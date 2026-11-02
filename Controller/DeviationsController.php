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
use KimaiPlugin\WeeklyPlanningBundle\Model\DeviationRow;
use KimaiPlugin\WeeklyPlanningBundle\Model\Hours;
use KimaiPlugin\WeeklyPlanningBundle\Service\Csv;
use KimaiPlugin\WeeklyPlanningBundle\Service\DateFormatter;
use KimaiPlugin\WeeklyPlanningBundle\Service\DeviationService;
use KimaiPlugin\WeeklyPlanningBundle\Service\ScopeResolver;
use KimaiPlugin\WeeklyPlanningBundle\Service\Week;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Weekly deviations: planned vs. actual for one week or a range of
 * weeks, computed live from the timesheets.
 */
#[Route(path: '/weekly-planning/deviations')]
#[IsGranted('weekly_planning')]
final class DeviationsController extends AbstractController
{
    private const MAX_WEEKS = 26;
    private const VIEWS = [
        'detail' => 'weekly_planning.view.detail',
        'status' => 'weekly_planning.view.status',
        'person' => 'weekly_planning.view.person',
        'customer' => 'weekly_planning.view.customer',
    ];

    public function __construct(
        private readonly ScopeResolver $scopes,
        private readonly DeviationService $deviations,
        private readonly DateFormatter $dates,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(path: '', name: 'weekly_planning_deviations', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $planner = $this->getCurrentUser();
        $scope = $this->scopes->resolve($planner);
        [$from, $to, $error] = $this->range($request, $planner);
        $all = $scope->canPlan() ? $this->deviations->forRange($scope, $from, $to) : [];
        $filters = $this->filters($request);
        $rows = $this->applyFilters($all, $filters);
        $view = (string) $request->query->get('view', 'detail');
        $view = \array_key_exists($view, self::VIEWS) ? $view : 'detail';

        $page = new PageSetup('weekly_planning.title.deviations');
        $page->setActionName('weekly_planning_deviations');

        return $this->render('@WeeklyPlanning/deviations.html.twig', [
            'page_setup' => $page,
            'scope' => $scope,
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'range_label' => $this->dates->range($from, $to),
            'error' => $error,
            'views' => self::VIEWS,
            'view' => $view,
            'filters' => $filters,
            'query' => array_filter(['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')] + $filters, static fn (string $value): bool => $value !== '' && $value !== '0'),
            'people' => $this->distinct($all, static fn (DeviationRow $r): array => [$r->userId, $r->userName]),
            'customers' => $this->distinct($all, static fn (DeviationRow $r): array => [$r->customerId, $r->customerName]),
            'status_labels' => DeviationRow::STATUS_LABELS,
            'rows' => $rows,
            'multi_week' => $from->getTimestamp() !== $to->getTimestamp(),
            'summary' => $this->summary($rows),
            'by_status' => $this->group($rows, static fn (DeviationRow $r): string => $r->getStatusLabel()),
            'by_person' => $this->group($rows, static fn (DeviationRow $r): string => $r->userName),
            'by_customer' => $this->group($rows, static fn (DeviationRow $r): string => $r->customerName),
        ]);
    }

    #[Route(path: '/export', name: 'weekly_planning_deviations_export', methods: ['GET'])]
    public function export(Request $request): Response
    {
        $planner = $this->getCurrentUser();
        $scope = $this->scopes->resolve($planner);
        [$from, $to] = $this->range($request, $planner);
        $rows = $this->applyFilters($scope->canPlan() ? $this->deviations->forRange($scope, $from, $to) : [], $this->filters($request));

        return Csv::response(
            \sprintf('weekly_deviations_%s_%s.csv', $from->format('Y-m-d'), $to->format('Y-m-d')),
            array_map(fn (string $key): string => $this->translator->trans($key), [
                'weekly_planning.week', 'weekly_planning.person', 'customer', 'project', 'weekly_planning.summary.planned_hours',
                'weekly_planning.actual_hours', 'weekly_planning.deviation', 'weekly_planning.deviation_percent', 'weekly_planning.status',
            ]),
            array_map(fn (DeviationRow $r): array => [
                $r->week, $r->userName, $r->customerName, $r->projectLabel,
                Hours::plain($r->assigned), Hours::plain($r->actual), Hours::plain($r->deviation),
                $r->percent === null ? '' : Hours::plain($r->percent), $this->translator->trans($r->getStatusLabel()),
            ], $rows)
        );
    }

    /**
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable, 2: TranslatableMessage|null}
     */
    private function range(Request $request, User $planner): array
    {
        $timezone = new \DateTimeZone($planner->getTimezone());
        $current = Week::current($timezone);
        $from = Week::parse($request->query->get('from'), $timezone) ?? $current;
        $to = Week::parse($request->query->get('to'), $timezone) ?? $from;
        $error = null;

        if ($to < $from) {
            [$from, $to] = [$to, $from];
        }
        $weeks = intdiv((int) $from->diff($to)->days, 7) + 1;
        if ($weeks > self::MAX_WEEKS) {
            $to = $from->modify('+' . (self::MAX_WEEKS - 1) . ' weeks');
            $error = new TranslatableMessage('weekly_planning.range_limited', ['%weeks%' => (string) self::MAX_WEEKS]);
        }

        return [$from, $to, $error];
    }

    /**
     * @return array{user: string, customer: string, status: string}
     */
    private function filters(Request $request): array
    {
        return [
            'user' => (string) $request->query->get('user', ''),
            'customer' => (string) $request->query->get('customer', ''),
            'status' => (string) $request->query->get('status', ''),
        ];
    }

    /**
     * @param list<DeviationRow> $rows
     * @param array{user: string, customer: string, status: string} $filters
     * @return list<DeviationRow>
     */
    private function applyFilters(array $rows, array $filters): array
    {
        return array_values(array_filter(
            $rows,
            static fn (DeviationRow $r): bool => ($filters['user'] === '' || (string) $r->userId === $filters['user'])
            && ($filters['customer'] === '' || (string) $r->customerId === $filters['customer'])
            && ($filters['status'] === '' || $r->status === $filters['status'])
        ));
    }

    /**
     * @param list<DeviationRow> $rows
     * @param callable(DeviationRow): array{0: int, 1: string} $pair id and name of a row
     * @return array<int, string>
     */
    private function distinct(array $rows, callable $pair): array
    {
        $values = [];
        foreach ($rows as $row) {
            [$id, $name] = $pair($row);
            $values[$id] = $name;
        }
        asort($values, SORT_NATURAL | SORT_FLAG_CASE);

        return $values;
    }

    /**
     * @param list<DeviationRow> $rows
     * @return array<string, string|int>
     */
    private function summary(array $rows): array
    {
        $assigned = 0.0;
        $actual = 0.0;
        $deviation = 0.0;
        $absolute = 0.0;
        $users = [];
        foreach ($rows as $row) {
            $assigned += $row->assigned;
            $actual += $row->actual;
            $deviation += $row->deviation;
            $absolute += abs($row->deviation);
            $users[$row->userId] = true;
        }

        return [
            'assigned' => Hours::format(Hours::round($assigned)),
            'actual' => Hours::format(Hours::round($actual)),
            'deviation' => Hours::format(Hours::round($deviation)),
            'absolute' => Hours::format(Hours::round($absolute)),
            'matched' => \count(array_filter($rows, static fn (DeviationRow $r): bool => $r->status === 'matched')),
            'unplanned' => \count(array_filter($rows, static fn (DeviationRow $r): bool => $r->status === 'unplanned')),
            'people' => \count($users),
        ];
    }

    /**
     * @param list<DeviationRow> $rows
     * @param callable(DeviationRow): string $key
     * @return list<array{label: string, count: int, assigned: string, actual: string, deviation: string, negative: bool}>
     */
    private function group(array $rows, callable $key): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $label = $key($row);
            $groups[$label] ??= ['count' => 0, 'assigned' => 0.0, 'actual' => 0.0, 'deviation' => 0.0];
            $groups[$label]['count']++;
            $groups[$label]['assigned'] += $row->assigned;
            $groups[$label]['actual'] += $row->actual;
            $groups[$label]['deviation'] += $row->deviation;
        }
        uasort($groups, static fn (array $a, array $b): int => $b['deviation'] <=> $a['deviation']);

        $result = [];
        foreach ($groups as $label => $group) {
            $result[] = [
                'label' => (string) $label,
                'count' => $group['count'],
                'assigned' => Hours::format(Hours::round($group['assigned'])),
                'actual' => Hours::format(Hours::round($group['actual'])),
                'deviation' => Hours::format(Hours::round($group['deviation'])),
                'negative' => $group['deviation'] < 0,
            ];
        }

        return $result;
    }

    private function getCurrentUser(): User
    {
        return $this->getUser();
    }
}
