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

use App\Entity\User;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * What one planner may see and change. Built once per request by
 * ScopeResolver; every controller re-checks posted ids against it.
 */
final class PlannerScope
{
    /**
     * @param array<int, User> $users enabled users, keyed by id, sorted by name
     * @param array<int, ProjectOption> $projects keyed by id, sorted by label
     * @param list<string> $teamNames teams the planner leads
     */
    public function __construct(
        private readonly User $planner,
        private readonly bool $admin,
        private readonly array $teamNames,
        private readonly array $users,
        private readonly array $projects,
    ) {
    }

    public function getPlanner(): User
    {
        return $this->planner;
    }

    public function isAdmin(): bool
    {
        return $this->admin;
    }

    /**
     * Admins always; team leads only when they lead at least one team.
     */
    public function canPlan(): bool
    {
        return $this->admin || \count($this->teamNames) > 0;
    }

    /**
     * @return list<string>
     */
    public function getTeamNames(): array
    {
        return $this->teamNames;
    }

    /**
     * @return array<int, User>
     */
    public function getUsers(): array
    {
        return $this->users;
    }

    public function getUser(int $id): ?User
    {
        return $this->users[$id] ?? null;
    }

    public function allowsUser(int $id): bool
    {
        return isset($this->users[$id]);
    }

    /**
     * @return array<int, ProjectOption>
     */
    public function getProjects(): array
    {
        return $this->projects;
    }

    public function getProject(int $id): ?ProjectOption
    {
        return $this->projects[$id] ?? null;
    }

    public function allowsProject(int $id): bool
    {
        return isset($this->projects[$id]);
    }

    /**
     * Filter for stored history (assignments / deviations). Admins see every
     * stored row, including people and projects that are now disabled or
     * hidden, so past weeks stay complete. Team leads only see rows for their
     * people on projects in their scope.
     */
    public function allowsRow(int $userId, int $projectId): bool
    {
        return $this->admin || ($this->allowsUser($userId) && $this->allowsProject($projectId));
    }

    public function getCaption(): TranslatableMessage
    {
        if ($this->admin) {
            return new TranslatableMessage('weekly_planning.scope.admin');
        }

        return new TranslatableMessage('weekly_planning.scope.teams', ['%teams%' => implode(', ', $this->teamNames)]);
    }
}
