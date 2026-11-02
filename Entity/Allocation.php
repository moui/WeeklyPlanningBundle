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

use App\Entity\Project;
use App\Entity\User;
use Doctrine\ORM\Mapping as ORM;

/**
 * Hours planned for one project inside a WeeklyPlan. The customer is not
 * stored: it always comes from the project, so it cannot drift.
 */
#[ORM\Entity]
#[ORM\Table(name: 'kimai2_weekly_planning_allocations')]
#[ORM\Index(name: 'IDX_WP_ALLOC_PLAN', columns: ['plan_id'])]
#[ORM\Index(name: 'IDX_WP_ALLOC_PROJECT', columns: ['project_id'])]
#[ORM\Index(name: 'IDX_WP_ALLOC_CREATED_BY', columns: ['created_by_id'])]
#[ORM\Index(name: 'IDX_WP_ALLOC_UPDATED_BY', columns: ['updated_by_id'])]
class Allocation
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: WeeklyPlan::class, inversedBy: 'allocations')]
    #[ORM\JoinColumn(name: 'plan_id', nullable: false, onDelete: 'CASCADE')]
    private WeeklyPlan $plan;

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(name: 'project_id', nullable: false, onDelete: 'CASCADE')]
    private Project $project;

    #[ORM\Column(type: 'decimal', precision: 6, scale: 2)]
    private string $hours = '0.00';

    #[ORM\Column(type: 'text', length: 65535, nullable: true)]
    private ?string $notes = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'updated_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $updatedBy = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(WeeklyPlan $plan, Project $project, float $hours, ?string $notes, ?User $actor)
    {
        $this->plan = $plan;
        $this->project = $project;
        $this->hours = number_format($hours, 2, '.', '');
        $this->notes = ($notes === null || trim($notes) === '') ? null : trim($notes);
        $this->createdBy = $actor;
        $this->updatedBy = $actor;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPlan(): WeeklyPlan
    {
        return $this->plan;
    }

    public function getProject(): Project
    {
        return $this->project;
    }

    public function getHours(): float
    {
        return (float) $this->hours;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function getUpdatedBy(): ?User
    {
        return $this->updatedBy;
    }
}
