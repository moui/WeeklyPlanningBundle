<?php

declare(strict_types=1);

/*
 * This file is part of the "WeeklyPlanningBundle" for Kimai.
 * Copyright (c) 2026 Mauricio Barrera (https://github.com/moui)
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\WeeklyPlanningBundle\Widget;

use App\Entity\Project;
use App\Repository\ProjectRepository;
use App\Widget\Type\AbstractWidget;
use App\Widget\WidgetInterface;
use KimaiPlugin\WeeklyPlanningBundle\Model\Hours;
use KimaiPlugin\WeeklyPlanningBundle\Service\ActualHours;
use KimaiPlugin\WeeklyPlanningBundle\Service\DateFormatter;
use KimaiPlugin\WeeklyPlanningBundle\Service\PlanningService;
use KimaiPlugin\WeeklyPlanningBundle\Service\Week;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * "My weekly plan": the signed-in user's own plan for this week,
 * next to what they have actually recorded so far. Visible to everyone; it
 * only ever reads the current user's rows.
 */
final class MyWeeklyPlanWidget extends AbstractWidget
{
    public const ID = 'WeeklyPlanningWidget';

    public function __construct(
        private readonly PlanningService $planning,
        private readonly ActualHours $actuals,
        private readonly ProjectRepository $projects,
        private readonly DateFormatter $dates,
    ) {
    }

    public function getId(): string
    {
        return self::ID;
    }

    public function getTitle(): string
    {
        return 'weekly_planning.widget.title';
    }

    public function getWidth(): int
    {
        return WidgetInterface::WIDTH_HALF;
    }

    public function getHeight(): int
    {
        return WidgetInterface::HEIGHT_LARGE;
    }

    public function getTemplateName(): string
    {
        return '@WeeklyPlanning/widget.html.twig';
    }

    /**
     * @param array<string, string|bool|int|null|array<string, mixed>> $options
     * @return array<string, mixed>
     */
    public function getData(array $options = []): mixed
    {
        $user = $this->getUser();
        $monday = Week::current($this->getTimezone());
        $plan = $this->planning->findPlan($this->planning->findPeriod($monday), $user);
        $actual = $this->actuals->byProjectForUser((int) $user->getId(), $monday, $monday->modify('+6 days'));

        $rows = [];
        $planned = 0.0;
        foreach ($plan?->getAllocations() ?? [] as $allocation) {
            $project = $allocation->getProject();
            $projectId = (int) $project->getId();
            $done = Hours::fromSeconds($actual[$projectId] ?? 0);
            unset($actual[$projectId]);
            $planned += $allocation->getHours();
            $rows[] = $this->row($project, $allocation->getHours(), $done, $allocation->getNotes());
        }

        // Hours on projects that were not in the plan come last.
        $unplanned = $actual === [] ? [] : $this->projects->findByIds(array_keys($actual));
        foreach ($unplanned as $project) {
            $rows[] = $this->row($project, 0.0, Hours::fromSeconds($actual[(int) $project->getId()]), null);
        }

        $done = Hours::round(array_sum(array_column($rows, 'actual_value')));

        return [
            'has_plan' => $plan !== null && \count($rows) > 0 && $planned > 0,
            'week_label' => $this->dates->week($monday),
            'rows' => $rows,
            'planned' => self::duration($planned),
            'actual' => self::duration($done),
            'capacity' => $plan !== null ? self::duration($plan->getCapacityHours()) : null,
        ];
    }

    /**
     * One row of the widget, laid out like Kimai's own "My projects" widget:
     * recorded vs. planned as a bar. state drives the colour (same palette as
     * the deviations page): over = red, under = grey, on = green, unplanned = orange.
     *
     * @return array{
     *     project: Project, notes: string|null, state: string, unplanned: bool, planned: string|null,
     *     actual: string, actual_value: float, width: float|int, caption: TranslatableMessage
     * }
     */
    private function row(Project $project, float $planned, float $actual, ?string $notes): array
    {
        $planned = Hours::round($planned);
        $actual = Hours::round($actual);
        $state = match (true) {
            $planned <= 0 => 'unplanned',
            $actual > $planned => 'over',
            $actual === $planned => 'on',
            default => 'under',
        };
        $percent = Hours::safePercent($actual, $planned);

        $caption = match ($state) {
            'unplanned' => new TranslatableMessage('weekly_planning.deviation_status.unplanned'),
            'on' => new TranslatableMessage('weekly_planning.widget.done'),
            'over' => new TranslatableMessage('weekly_planning.widget.done_over', [
                '%percent%' => number_format((float) $percent, 0),
                '%duration%' => self::duration($actual - $planned),
            ]),
            default => new TranslatableMessage('weekly_planning.widget.done_under', [
                '%percent%' => number_format((float) $percent, 0),
                '%duration%' => self::duration($planned - $actual),
            ]),
        };

        return [
            'project' => $project,
            'notes' => $notes !== null && trim($notes) !== '' ? trim($notes) : null,
            'state' => $state,
            'unplanned' => $state === 'unplanned',
            'planned' => $planned > 0 ? self::duration($planned) : null,
            'actual' => self::duration($actual),
            'actual_value' => $actual,
            'width' => $state === 'unplanned' ? 100 : min(100.0, (float) $percent),
            'caption' => $caption,
        ];
    }

    /**
     * Decimal hours as H:MM, the format Kimai's own widgets use.
     */
    private static function duration(float $hours): string
    {
        $minutes = (int) round($hours * 60);

        return \sprintf('%d:%02d', intdiv($minutes, 60), $minutes % 60);
    }
}
