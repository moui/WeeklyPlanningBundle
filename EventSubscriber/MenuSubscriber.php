<?php

declare(strict_types=1);

/*
 * This file is part of the "WeeklyPlanningBundle" for Kimai.
 * Copyright (c) 2026 Mauricio Barrera (https://github.com/moui)
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\WeeklyPlanningBundle\EventSubscriber;

use App\Event\ConfigureMainMenuEvent;
use App\Utils\MenuItemModel;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final class MenuSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly AuthorizationCheckerInterface $security)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ConfigureMainMenuEvent::class => ['onMenuConfigure', 100],
        ];
    }

    public function onMenuConfigure(ConfigureMainMenuEvent $event): void
    {
        if (!$this->security->isGranted('weekly_planning')) {
            return;
        }

        $section = new MenuItemModel('weekly_planning_menu', 'weekly_planning.menu', null, [], 'calendar');
        $section->addChild(new MenuItemModel('weekly_planning', 'weekly_planning.title.planning', 'weekly_planning', [], 'calendar'));
        $section->addChild(new MenuItemModel('weekly_planning_assignments', 'weekly_planning.title.assignments', 'weekly_planning_assignments', [], 'list'));
        $section->addChild(new MenuItemModel('weekly_planning_deviations', 'weekly_planning.title.deviations', 'weekly_planning_deviations', [], 'reporting'));

        $event->getMenu()->addChild($section);
    }
}
