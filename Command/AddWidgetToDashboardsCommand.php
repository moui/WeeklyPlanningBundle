<?php

declare(strict_types=1);

/*
 * This file is part of the "WeeklyPlanningBundle" for Kimai.
 * Copyright (c) 2026 Mauricio Barrera (https://github.com/moui)
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\WeeklyPlanningBundle\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Widget\DashboardService;
use App\Widget\WidgetService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Users who never customised their dashboard get the widget from Kimai's
 * default layout (DashboardSubscriber). Users with a saved layout do not, so
 * this appends it to their saved dashboard. Re-running is harmless.
 */
#[AsCommand(
    name: 'kimai:weekly-planning:add-widget',
    description: 'Add the "My weekly plan" widget to every user dashboard that was customised'
)]
final class AddWidgetToDashboardsCommand extends Command
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly DashboardService $dashboards,
        private readonly WidgetService $widgets,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('apply', null, InputOption::VALUE_NONE, 'Save the change; without it the command only reports');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $widget = $this->widgets->getWidget('WeeklyPlanningWidget');
        $apply = (bool) $input->getOption('apply');
        $defaultLayout = 0;
        $present = 0;
        $changed = 0;

        foreach ($this->users->findAll() as $user) {
            if (!$user instanceof User || !$user->isEnabled()) {
                continue;
            }

            if ($this->dashboards->getBookmark($user) === null) {
                $defaultLayout++;
                continue;
            }

            $existing = array_filter($this->dashboards->getUserWidgets($user), static fn (array $setting): bool => $setting['id'] === $widget->getId());
            if (\count($existing) > 0) {
                $present++;
                continue;
            }

            if ($apply) {
                $this->dashboards->addUserWidget($user, $widget);
            }
            $changed++;
        }

        $io->table(['Enabled users', 'Count'], [
            ['default layout (already shown)', $defaultLayout],
            ['already on dashboard', $present],
            [$apply ? 'added' : 'would add', $changed],
        ]);

        if (!$apply) {
            $io->note('Dry-run: re-run with --apply to update the saved dashboards.');
        }

        return Command::SUCCESS;
    }
}
