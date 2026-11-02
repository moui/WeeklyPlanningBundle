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

use App\Entity\Project;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use KimaiPlugin\WeeklyPlanningBundle\Entity\Allocation;
use KimaiPlugin\WeeklyPlanningBundle\Entity\PlanningPeriod;
use KimaiPlugin\WeeklyPlanningBundle\Entity\WeeklyPlan;

/**
 * Persistence for periods and plans. Validation and scope checks happen in
 * PlanValidator / the controllers before anything reaches this class.
 */
final class PlanningService
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function findPeriod(\DateTimeImmutable $monday): ?PlanningPeriod
    {
        return $this->em->getRepository(PlanningPeriod::class)->findOneBy(['weekStart' => $monday]);
    }

    public function getOrCreatePeriod(\DateTimeImmutable $monday): PlanningPeriod
    {
        $period = $this->findPeriod($monday);
        if ($period === null) {
            $period = new PlanningPeriod($monday);
            $this->em->persist($period);
            $this->em->flush();
        }

        return $period;
    }

    public function setStatus(\DateTimeImmutable $monday, string $status): PlanningPeriod
    {
        $period = $this->getOrCreatePeriod($monday);
        $period->setStatus($status);
        $this->em->flush();

        return $period;
    }

    public function findPlan(?PlanningPeriod $period, User $user): ?WeeklyPlan
    {
        if ($period === null || $period->getId() === null) {
            return null;
        }

        return $this->em->getRepository(WeeklyPlan::class)->findOneBy(['period' => $period, 'user' => $user]);
    }

    /**
     * Most recent plan with at least one allocation, strictly before the week.
     */
    public function findLatestEarlierPlan(User $user, \DateTimeImmutable $monday): ?WeeklyPlan
    {
        /** @var list<WeeklyPlan> $plans */
        $plans = $this->em->createQueryBuilder()
            ->select('pl', 'pp')
            ->from(WeeklyPlan::class, 'pl')
            ->join('pl.period', 'pp')
            ->where('pl.user = :user')
            ->andWhere('pp.weekStart < :monday')
            ->orderBy('pp.weekStart', 'DESC')
            ->setParameter('user', $user)
            ->setParameter('monday', $monday, 'date_immutable')
            ->setMaxResults(12)
            ->getQuery()
            ->getResult();

        foreach ($plans as $plan) {
            if (\count($plan->getAllocations()) > 0) {
                return $plan;
            }
        }

        return null;
    }

    /**
     * Replaces the person's allocations for the week in one flush.
     *
     * @param list<array{project: int, hours: float, notes: string}> $rows already validated
     */
    public function savePlan(\DateTimeImmutable $monday, User $user, float $capacity, array $rows, User $actor): WeeklyPlan
    {
        $period = $this->getOrCreatePeriod($monday);
        $plan = $this->findPlan($period, $user);

        if ($plan === null) {
            $plan = new WeeklyPlan($period, $user);
            $this->em->persist($plan);
        } else {
            $plan->clearAllocations();
            // Delete the old rows before inserting, inside the same flush.
            $this->em->flush();
        }

        $plan->setCapacityHours($capacity);
        $plan->touch();

        foreach ($rows as $row) {
            /** @var Project $project */
            $project = $this->em->getReference(Project::class, $row['project']);
            $plan->addAllocation(new Allocation($plan, $project, $row['hours'], $row['notes'], $actor));
        }

        $this->em->flush();

        return $plan;
    }

    /**
     * @return list<WeeklyPlan>
     */
    public function findPlansForPeriod(PlanningPeriod $period): array
    {
        return $this->em->createQueryBuilder()
            ->select('pl', 'a', 'u', 'p', 'c')
            ->from(WeeklyPlan::class, 'pl')
            ->join('pl.user', 'u')
            ->leftJoin('pl.allocations', 'a')
            ->leftJoin('a.project', 'p')
            ->leftJoin('p.customer', 'c')
            ->where('pl.period = :period')
            ->setParameter('period', $period)
            ->getQuery()
            ->getResult();
    }
}
