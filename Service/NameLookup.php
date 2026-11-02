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

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Display names for ids found in stored plans and timesheets, including
 * users and projects that are disabled or hidden today.
 */
final class NameLookup
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @param list<int> $ids
     * @return array<int, string>
     */
    public function users(array $ids): array
    {
        if (\count($ids) === 0) {
            return [];
        }

        /** @var list<array{id: int|string, name: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            "SELECT id, COALESCE(NULLIF(alias, ''), username) AS name FROM kimai2_users WHERE id IN (:ids)",
            ['ids' => array_values(array_unique($ids))],
            ['ids' => ArrayParameterType::INTEGER]
        );

        $names = [];
        foreach ($rows as $row) {
            $names[(int) $row['id']] = (string) $row['name'];
        }

        return $names;
    }

    /**
     * @param list<int> $ids
     * @return array<int, array{name: string, customer_id: int, customer_name: string}>
     */
    public function projects(array $ids): array
    {
        if (\count($ids) === 0) {
            return [];
        }

        /** @var list<array{id: int|string, name: string, customer_id: int|string, customer_name: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT p.id, p.name, c.id AS customer_id, c.name AS customer_name
             FROM kimai2_projects p INNER JOIN kimai2_customers c ON c.id = p.customer_id
             WHERE p.id IN (:ids)',
            ['ids' => array_values(array_unique($ids))],
            ['ids' => ArrayParameterType::INTEGER]
        );

        $projects = [];
        foreach ($rows as $row) {
            $projects[(int) $row['id']] = [
                'name' => (string) $row['name'],
                'customer_id' => (int) $row['customer_id'],
                'customer_name' => (string) $row['customer_name'],
            ];
        }

        return $projects;
    }
}
