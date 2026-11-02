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

/**
 * A project as offered in the planning editor, labelled "<project> [<customer>]"
 * so projects with the same name under different customers stay apart.
 */
final class ProjectOption
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly int $customerId,
        public readonly string $customerName,
    ) {
    }

    public function getLabel(): string
    {
        return self::label($this->name, $this->customerName);
    }

    public static function label(string $project, string $customer): string
    {
        return $project . ' [' . $customer . ']';
    }
}
