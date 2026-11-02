<?php

declare(strict_types=1);

/*
 * This file is part of the "WeeklyPlanningBundle" for Kimai.
 * Copyright (c) 2026 Mauricio Barrera (https://github.com/moui)
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\WeeklyPlanningBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Creates the three planning tables. Foreign keys point at Kimai's own users
 * and projects, so deleting a user or project in Kimai removes their plans
 * instead of leaving orphans.
 */
final class Version20261016000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Weekly planning: periods, plans and allocations';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE kimai2_weekly_planning_periods (
                id INT AUTO_INCREMENT NOT NULL,
                week_start DATE NOT NULL COMMENT '(DC2Type:date_immutable)',
                status VARCHAR(20) NOT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX UNIQ_WP_PERIOD_WEEK (week_start),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE kimai2_weekly_planning_plans (
                id INT AUTO_INCREMENT NOT NULL,
                period_id INT NOT NULL,
                user_id INT NOT NULL,
                capacity_hours NUMERIC(6, 2) NOT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX IDX_WP_PLAN_PERIOD (period_id),
                INDEX IDX_WP_PLAN_USER (user_id),
                UNIQUE INDEX UNIQ_WP_PLAN_PERIOD_USER (period_id, user_id),
                PRIMARY KEY(id),
                CONSTRAINT FK_WP_PLAN_PERIOD FOREIGN KEY (period_id) REFERENCES kimai2_weekly_planning_periods (id) ON DELETE CASCADE,
                CONSTRAINT FK_WP_PLAN_USER FOREIGN KEY (user_id) REFERENCES kimai2_users (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE kimai2_weekly_planning_allocations (
                id INT AUTO_INCREMENT NOT NULL,
                plan_id INT NOT NULL,
                project_id INT NOT NULL,
                hours NUMERIC(6, 2) NOT NULL,
                notes TEXT DEFAULT NULL,
                created_by_id INT DEFAULT NULL,
                updated_by_id INT DEFAULT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX IDX_WP_ALLOC_PLAN (plan_id),
                INDEX IDX_WP_ALLOC_PROJECT (project_id),
                INDEX IDX_WP_ALLOC_CREATED_BY (created_by_id),
                INDEX IDX_WP_ALLOC_UPDATED_BY (updated_by_id),
                PRIMARY KEY(id),
                CONSTRAINT FK_WP_ALLOC_PLAN FOREIGN KEY (plan_id) REFERENCES kimai2_weekly_planning_plans (id) ON DELETE CASCADE,
                CONSTRAINT FK_WP_ALLOC_PROJECT FOREIGN KEY (project_id) REFERENCES kimai2_projects (id) ON DELETE CASCADE,
                CONSTRAINT FK_WP_ALLOC_CREATED_BY FOREIGN KEY (created_by_id) REFERENCES kimai2_users (id) ON DELETE SET NULL,
                CONSTRAINT FK_WP_ALLOC_UPDATED_BY FOREIGN KEY (updated_by_id) REFERENCES kimai2_users (id) ON DELETE SET NULL
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE kimai2_weekly_planning_allocations');
        $this->addSql('DROP TABLE kimai2_weekly_planning_plans');
        $this->addSql('DROP TABLE kimai2_weekly_planning_periods');
    }

    public function isTransactional(): bool
    {
        // MySQL DDL commits implicitly; a wrapping transaction only hides that.
        return false;
    }
}
