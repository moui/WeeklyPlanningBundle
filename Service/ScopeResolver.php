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
use App\Repository\UserRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use KimaiPlugin\WeeklyPlanningBundle\Model\PlannerScope;
use KimaiPlugin\WeeklyPlanningBundle\Model\ProjectOption;

/**
 * Decides who and what a planner may plan:
 *
 * - admins (ROLE_ADMIN / ROLE_SUPER_ADMIN) get every enabled user and every
 *   visible project of a visible customer;
 * - team leads get the enabled members of the teams they lead, and the
 *   projects where both the project and its customer either have no team
 *   restriction or are assigned to one of those teams;
 * - anyone else gets nothing (canPlan() is false).
 */
final class ScopeResolver
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly Connection $connection,
    ) {
    }

    public function resolve(User $planner): PlannerScope
    {
        $admin = $planner->isAdmin(false);
        $teamIds = [];
        $teamNames = [];
        $users = [];

        if ($admin) {
            foreach ($this->users->findAll() as $user) {
                if ($user->isEnabled()) {
                    $users[(int) $user->getId()] = $user;
                }
            }
        } else {
            foreach ($planner->getTeams() as $team) {
                if (!$planner->isTeamleadOf($team)) {
                    continue;
                }
                $teamIds[] = (int) $team->getId();
                $teamNames[] = (string) $team->getName();
                foreach ($team->getUsers() as $user) {
                    if ($user->isEnabled()) {
                        $users[(int) $user->getId()] = $user;
                    }
                }
            }
            sort($teamNames);
        }

        uasort($users, static fn (User $a, User $b): int => strcasecmp($a->getDisplayName(), $b->getDisplayName()));

        $projects = ($admin || \count($teamIds) > 0) ? $this->loadProjects($admin ? null : $teamIds) : [];

        return new PlannerScope($planner, $admin, $teamNames, $users, $projects);
    }

    /**
     * @param list<int>|null $teamIds null = no team restriction (admin)
     * @return array<int, ProjectOption>
     */
    private function loadProjects(?array $teamIds): array
    {
        $sql = <<<'SQL'
            SELECT p.id, p.name, c.id AS customer_id, c.name AS customer_name
            FROM kimai2_projects p
            INNER JOIN kimai2_customers c ON c.id = p.customer_id
            WHERE p.visible = 1 AND c.visible = 1
            SQL;
        $params = [];
        $types = [];

        if ($teamIds !== null) {
            $sql .= <<<'SQL'
                 AND (
                    NOT EXISTS (SELECT 1 FROM kimai2_projects_teams pt WHERE pt.project_id = p.id)
                    OR EXISTS (SELECT 1 FROM kimai2_projects_teams pt WHERE pt.project_id = p.id AND pt.team_id IN (:teams))
                 )
                 AND (
                    NOT EXISTS (SELECT 1 FROM kimai2_customers_teams ct WHERE ct.customer_id = c.id)
                    OR EXISTS (SELECT 1 FROM kimai2_customers_teams ct WHERE ct.customer_id = c.id AND ct.team_id IN (:teams))
                 )
                SQL;
            $params['teams'] = $teamIds;
            $types['teams'] = ArrayParameterType::INTEGER;
        }

        $sql .= ' ORDER BY c.name, p.name';

        $projects = [];
        /** @var list<array{id: int|string, name: string, customer_id: int|string, customer_name: string}> $rows */
        $rows = $this->connection->fetchAllAssociative($sql, $params, $types);
        foreach ($rows as $row) {
            $option = new ProjectOption((int) $row['id'], (string) $row['name'], (int) $row['customer_id'], (string) $row['customer_name']);
            $projects[$option->id] = $option;
        }

        uasort($projects, static fn (ProjectOption $a, ProjectOption $b): int => strcasecmp($a->getLabel(), $b->getLabel()));

        return $projects;
    }
}
