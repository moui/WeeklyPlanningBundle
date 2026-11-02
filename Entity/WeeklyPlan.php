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

use App\Entity\User;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * One person's plan for one week. capacity_hours is a snapshot of the expected
 * hours at the time of saving, so later contract changes do not rewrite how
 * full a past week looked.
 */
#[ORM\Entity]
#[ORM\Table(name: 'kimai2_weekly_planning_plans')]
#[ORM\UniqueConstraint(name: 'UNIQ_WP_PLAN_PERIOD_USER', columns: ['period_id', 'user_id'])]
#[ORM\Index(name: 'IDX_WP_PLAN_PERIOD', columns: ['period_id'])]
#[ORM\Index(name: 'IDX_WP_PLAN_USER', columns: ['user_id'])]
class WeeklyPlan
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: PlanningPeriod::class)]
    #[ORM\JoinColumn(name: 'period_id', nullable: false, onDelete: 'CASCADE')]
    private PlanningPeriod $period;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(name: 'capacity_hours', type: 'decimal', precision: 6, scale: 2)]
    private string $capacityHours = '0.00';

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, Allocation> */
    #[ORM\OneToMany(mappedBy: 'plan', targetEntity: Allocation::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $allocations;

    public function __construct(PlanningPeriod $period, User $user)
    {
        $this->period = $period;
        $this->user = $user;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
        $this->allocations = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPeriod(): PlanningPeriod
    {
        return $this->period;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getCapacityHours(): float
    {
        return (float) $this->capacityHours;
    }

    public function setCapacityHours(float $hours): void
    {
        $this->capacityHours = number_format($hours, 2, '.', '');
    }

    /**
     * @return list<Allocation>
     */
    public function getAllocations(): array
    {
        return array_values($this->allocations->toArray());
    }

    public function clearAllocations(): void
    {
        $this->allocations->clear();
    }

    public function addAllocation(Allocation $allocation): void
    {
        $this->allocations->add($allocation);
    }

    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
