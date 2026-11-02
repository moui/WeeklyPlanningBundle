<?php

declare(strict_types=1);

/*
 * This file is part of the "WeeklyPlanningBundle" for Kimai.
 * Copyright (c) 2026 Mauricio Barrera (https://github.com/moui)
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\WeeklyPlanningBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * One Monday–Sunday week. Rows are created lazily, the first time someone
 * saves a plan or changes the status for that week.
 */
#[ORM\Entity]
#[ORM\Table(name: 'kimai2_weekly_planning_periods')]
#[ORM\UniqueConstraint(name: 'UNIQ_WP_PERIOD_WEEK', columns: ['week_start'])]
class PlanningPeriod
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_CLOSED = 'closed';
    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_PUBLISHED, self::STATUS_CLOSED];

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'week_start', type: 'date_immutable')]
    private \DateTimeImmutable $weekStart;

    #[ORM\Column(type: 'string', length: 20)]
    private string $status = self::STATUS_DRAFT;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(\DateTimeImmutable $weekStart)
    {
        $this->weekStart = $weekStart->setTime(0, 0);
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getWeekStart(): \DateTimeImmutable
    {
        return $this->weekStart;
    }

    public function getWeekEnd(): \DateTimeImmutable
    {
        return $this->weekStart->modify('+6 days');
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        if (!\in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException('Invalid period status.');
        }
        $this->status = $status;
    }

    public function isClosed(): bool
    {
        return $this->status === self::STATUS_CLOSED;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
