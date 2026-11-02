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
use KimaiPlugin\WeeklyPlanningBundle\Entity\PlanningPeriod;
use KimaiPlugin\WeeklyPlanningBundle\Model\Hours;
use KimaiPlugin\WeeklyPlanningBundle\Model\PlannerScope;
use KimaiPlugin\WeeklyPlanningBundle\Service\ActualHours;
use KimaiPlugin\WeeklyPlanningBundle\Service\BudgetForecast;
use KimaiPlugin\WeeklyPlanningBundle\Service\CapacityCalculator;
use KimaiPlugin\WeeklyPlanningBundle\Service\DateFormatter;
use KimaiPlugin\WeeklyPlanningBundle\Service\PlanningService;
use KimaiPlugin\WeeklyPlanningBundle\Service\PlanValidator;
use KimaiPlugin\WeeklyPlanningBundle\Service\ScopeResolver;
use KimaiPlugin\WeeklyPlanningBundle\Service\Week;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Weekly planning: edit one person's allocations for one week.
 * Every write re-resolves the planner's scope and re-validates posted ids.
 */
#[Route(path: '/weekly-planning')]
#[IsGranted('weekly_planning')]
final class PlanningController extends AbstractController
{
    /**
     * Translation keys of the period statuses.
     */
    public const STATUS_LABELS = [
        PlanningPeriod::STATUS_DRAFT => 'weekly_planning.period_status.draft',
        PlanningPeriod::STATUS_PUBLISHED => 'weekly_planning.period_status.published',
        PlanningPeriod::STATUS_CLOSED => 'weekly_planning.period_status.closed',
    ];

    public function __construct(
        private readonly ScopeResolver $scopes,
        private readonly PlanningService $planning,
        private readonly CapacityCalculator $capacity,
        private readonly ActualHours $actuals,
        private readonly PlanValidator $validator,
        private readonly BudgetForecast $forecast,
        private readonly DateFormatter $dates,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(path: '', name: 'weekly_planning', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $planner = $this->getCurrentUser();
        $scope = $this->scopes->resolve($planner);
        $monday = Week::parse($request->query->get('week'), $this->timezone($planner));
        $notice = null;

        if ($monday === null) {
            [$monday, $notice] = $this->firstOpenWeek(Week::current($this->timezone($planner)));
        }

        $target = $scope->getUser((int) $request->query->get('user', 0)) ?? $this->firstUser($scope);

        return $this->renderPage($scope, $monday, $target, null, [], Response::HTTP_OK, $notice);
    }

    /**
     * Opening the page without a week lands on the first week that can still be
     * planned: a published or closed current week is done, so skip ahead to the
     * next week that is a draft or has no period yet. An explicit ?week= (the
     * week navigation) is always honoured.
     *
     * @return array{0: \DateTimeImmutable, 1: TranslatableMessage|null}
     */
    private function firstOpenWeek(\DateTimeImmutable $current): array
    {
        $monday = $current;

        for ($i = 0; $i < 52; $i++) {
            $period = $this->planning->findPeriod($monday);
            if ($period === null || $period->getStatus() === PlanningPeriod::STATUS_DRAFT) {
                break;
            }
            $monday = $monday->modify('+7 days');
        }

        if ($monday->getTimestamp() === $current->getTimestamp()) {
            return [$monday, null];
        }

        return [$monday, new TranslatableMessage(
            $this->planning->findPeriod($current)?->getStatus() === PlanningPeriod::STATUS_CLOSED
                ? 'weekly_planning.week_skipped.closed'
                : 'weekly_planning.week_skipped.published',
            ['%week%' => $this->dates->week($current)]
        )];
    }

    #[Route(path: '/save', name: 'weekly_planning_save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        $planner = $this->getCurrentUser();
        $scope = $this->scopes->resolve($planner);
        $monday = Week::parse((string) $request->request->get('week'), $this->timezone($planner));
        $target = $scope->getUser((int) $request->request->get('user', 0));

        if (!$this->isCsrfTokenValid('weekly_planning_save', (string) $request->request->get('_token'))) {
            $this->flashError('action.csrf.error');

            return $this->redirectToPage($monday, $target);
        }

        if ($monday === null || $target === null || !$scope->canPlan()) {
            $this->flashError('weekly_planning.flash.out_of_scope');

            return $this->redirectToPage($monday, null);
        }

        $period = $this->planning->findPeriod($monday);
        $posted = $request->request->all('rows');
        $result = $this->validator->normalize($posted, $scope);
        $errors = $result['errors'];

        if ($period !== null && $period->isClosed()) {
            $errors = [new TranslatableMessage('weekly_planning.closed_notice')];
        } elseif (\count($errors) === 0) {
            $errors = $this->validator->checkTotals($result['rows'], $this->capacity->forWeek($target, $monday));
        }

        if (\count($errors) > 0) {
            return $this->renderPage($scope, $monday, $target, $result['draft'], $errors, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $capacity = $this->capacity->forWeek($target, $monday);
        $this->planning->savePlan($monday, $target, $capacity, $result['rows'], $planner);

        $total = PlanValidator::total($result['rows']);
        $this->flashSuccess('weekly_planning.flash.saved');
        if ($total < $capacity) {
            $this->flashWarning('weekly_planning.flash.capacity_left');
        }

        return $this->redirectToPage($monday, $target);
    }

    #[Route(path: '/status', name: 'weekly_planning_status', methods: ['POST'])]
    public function status(Request $request): RedirectResponse
    {
        $planner = $this->getCurrentUser();
        $scope = $this->scopes->resolve($planner);
        $monday = Week::parse((string) $request->request->get('week'), $this->timezone($planner));
        $target = $scope->getUser((int) $request->request->get('user', 0));
        $status = (string) $request->request->get('status');

        if (!$this->isCsrfTokenValid('weekly_planning_status', (string) $request->request->get('_token'))) {
            $this->flashError('action.csrf.error');
        } elseif ($monday === null || !$scope->canPlan() || !\in_array($status, PlanningPeriod::STATUSES, true)) {
            $this->flashError('weekly_planning.flash.status_failed');
        } else {
            $this->planning->setStatus($monday, $status);
            $this->flashSuccess('weekly_planning.flash.status_updated');
        }

        return $this->redirectToPage($monday, $target);
    }

    /**
     * @param list<array{project: int|null, hours: string, notes: string}>|null $draft posted rows to show again, or null to load
     * @param list<TranslatableMessage> $errors
     */
    private function renderPage(PlannerScope $scope, \DateTimeImmutable $monday, ?User $target, ?array $draft, array $errors, int $status, ?TranslatableMessage $notice = null): Response
    {
        $period = $this->planning->findPeriod($monday);
        $info = null;

        if ($target !== null && $draft === null) {
            [$draft, $info] = $this->loadDraft($scope, $period, $target, $monday);
        }
        $draft ??= [];
        if (\count($draft) === 0) {
            $draft[] = ['project' => null, 'hours' => '', 'notes' => ''];
        }

        $capacity = $target !== null ? $this->capacity->forWeek($target, $monday) : 0.0;
        $assigned = PlanValidator::total($draft);
        [$plannedUsers, $savedHours] = $this->periodSummary($scope, $period);

        $persons = [];
        foreach ($scope->getUsers() as $id => $user) {
            $email = (string) $user->getEmail();
            $persons[$id] = $user->getDisplayName() . ' (' . ($email !== '' ? $email : $this->translator->trans('weekly_planning.no_email')) . ')';
        }
        $today = new \DateTimeImmutable('today', $this->timezone($scope->getPlanner()));
        $budgets = $this->budgets($scope, $monday, $today, $target);

        $projects = [];
        foreach ($scope->getProjects() as $id => $project) {
            // Projects with a budget say how much is left right in the picker.
            $projects[$id] = $project->getLabel() . (isset($budgets[$id]) ? ' · ' . $this->translator->trans('weekly_planning.budget.left', ['%hours%' => Hours::format($budgets[$id]['open'])]) : '');
        }

        $page = new PageSetup('weekly_planning.title.planning');
        $page->setActionName('weekly_planning');

        return $this->render('@WeeklyPlanning/planning.html.twig', [
            'page_setup' => $page,
            'scope' => $scope,
            'week' => $monday->format('Y-m-d'),
            'week_label' => $this->dates->week($monday),
            'prev_week' => $monday->modify('-7 days')->format('Y-m-d'),
            'next_week' => $monday->modify('+7 days')->format('Y-m-d'),
            'status' => $period?->getStatus() ?? PlanningPeriod::STATUS_DRAFT,
            'status_labels' => self::STATUS_LABELS,
            'closed' => $period?->isClosed() ?? false,
            'persons' => $persons,
            'target' => $target,
            'projects' => $projects,
            'rows' => $draft,
            'errors' => $errors,
            'info' => $info,
            'week_notice' => $notice,
            'budgets' => $budgets,
            'capacity' => $capacity,
            'capacity_formatted' => Hours::format($capacity),
            'assigned_formatted' => Hours::format($assigned),
            'difference_formatted' => Hours::format(Hours::round($capacity - $assigned)),
            'over_capacity' => $assigned > $capacity,
            'planned_users' => $plannedUsers,
            'saved_hours' => Hours::format($savedHours),
        ], new Response(null, $status));
    }

    /**
     * Saved plan if there is one; otherwise an unsaved draft from last week's
     * actual hours, or else from the person's most recent saved plan.
     *
     * @return array{0: list<array{project: int|null, hours: string, notes: string}>, 1: TranslatableMessage|null}
     */
    private function loadDraft(PlannerScope $scope, ?PlanningPeriod $period, User $target, \DateTimeImmutable $monday): array
    {
        $plan = $this->planning->findPlan($period, $target);
        if ($plan !== null && \count($plan->getAllocations()) > 0) {
            return [$this->rowsFromPlan($plan->getAllocations(), $scope, false), null];
        }

        $previousMonday = $monday->modify('-7 days');
        $previousSunday = $monday->modify('-1 day');
        $rows = [];
        foreach ($this->actuals->byProjectForUser((int) $target->getId(), $previousMonday, $previousSunday) as $projectId => $seconds) {
            $hours = Hours::fromSeconds($seconds);
            if ($hours > 0 && $scope->allowsProject($projectId)) {
                $rows[] = ['project' => $projectId, 'hours' => Hours::plain($hours), 'notes' => ''];
            }
        }
        if (\count($rows) > 0) {
            return [$rows, new TranslatableMessage('weekly_planning.prefill.actuals', [
                '%from%' => $this->dates->date($previousMonday),
                '%to%' => $this->dates->date($previousSunday),
            ])];
        }

        $earlier = $this->planning->findLatestEarlierPlan($target, $monday);
        if ($earlier !== null) {
            $rows = $this->rowsFromPlan($earlier->getAllocations(), $scope, true);
            if (\count($rows) > 0) {
                return [$rows, new TranslatableMessage('weekly_planning.prefill.earlier', [
                    '%week%' => $this->dates->week($earlier->getPeriod()->getWeekStart()),
                ])];
            }
        }

        return [[], null];
    }

    /**
     * @param list<\KimaiPlugin\WeeklyPlanningBundle\Entity\Allocation> $allocations
     * @return list<array{project: int|null, hours: string, notes: string}>
     */
    private function rowsFromPlan(array $allocations, PlannerScope $scope, bool $onlyInScope): array
    {
        $rows = [];
        foreach ($allocations as $allocation) {
            $projectId = (int) $allocation->getProject()->getId();
            if ($onlyInScope && !$scope->allowsProject($projectId)) {
                continue;
            }
            $rows[] = ['project' => $projectId, 'hours' => Hours::plain($allocation->getHours()), 'notes' => (string) $allocation->getNotes()];
        }

        return $rows;
    }

    /**
     * @return array{0: int, 1: float}
     */
    private function periodSummary(PlannerScope $scope, ?PlanningPeriod $period): array
    {
        if ($period === null) {
            return [0, 0.0];
        }
        $users = 0;
        $hours = 0.0;
        foreach ($this->planning->findPlansForPeriod($period) as $plan) {
            if (!$scope->isAdmin() && !$scope->allowsUser((int) $plan->getUser()->getId())) {
                continue;
            }
            $users++;
            foreach ($plan->getAllocations() as $allocation) {
                $hours += $allocation->getHours();
            }
        }

        return [$users, Hours::round($hours)];
    }

    private function firstUser(PlannerScope $scope): ?User
    {
        $users = $scope->getUsers();

        return \count($users) > 0 ? reset($users) : null;
    }

    /**
     * Budget status for every in-scope project with a time budget, plus the
     * hours other people already have planned on it this week (the person
     * being edited is left out: the form replaces their saved plan).
     *
     * @return array<int, array{
     *     id: int, monthly: bool, budget: float, spent: float, open: float, usage: ?float,
     *     rate: float, weeks: ?float, exhaustion: ?string, risk: string, risk_label: string,
     *     planned: float, planned_others: float
     * }>
     */
    private function budgets(PlannerScope $scope, \DateTimeImmutable $monday, \DateTimeImmutable $today, ?User $target): array
    {
        $budgets = $this->forecast->forProjects(array_map('intval', array_keys($scope->getProjects())), $today, $monday);
        $targetId = $target !== null ? (int) $target->getId() : 0;

        $result = [];
        foreach ($budgets as $id => $budget) {
            $budget['planned_others'] = Hours::round($budget['planned'] - ($budget['planned_by_user'][$targetId] ?? 0.0));
            unset($budget['planned_by_user']);
            $result[$id] = $budget;
        }

        return $result;
    }

    private function redirectToPage(?\DateTimeImmutable $monday, ?User $target): RedirectResponse
    {
        return $this->redirectToRoute('weekly_planning', array_filter([
            'week' => $monday?->format('Y-m-d'),
            'user' => $target?->getId(),
        ], static fn (string|int|null $value): bool => $value !== null));
    }

    private function timezone(User $user): \DateTimeZone
    {
        return new \DateTimeZone($user->getTimezone());
    }

    private function getCurrentUser(): User
    {
        return $this->getUser();
    }
}
