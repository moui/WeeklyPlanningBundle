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

use App\Configuration\LocaleService;

/**
 * Dates and week ranges in the current user's locale, with the same date
 * pattern Kimai uses for its own short dates.
 */
final class DateFormatter
{
    public function __construct(private readonly LocaleService $locales)
    {
    }

    public function date(\DateTimeInterface $date): string
    {
        $locale = \Locale::getDefault();
        $formatter = new \IntlDateFormatter(
            $locale,
            \IntlDateFormatter::SHORT,
            \IntlDateFormatter::NONE,
            $date->getTimezone(),
            \IntlDateFormatter::GREGORIAN,
            $this->locales->getDateFormat($locale)
        );

        return (string) $formatter->format($date);
    }

    /**
     * "<Monday> – <Sunday>" of the week starting on $monday.
     */
    public function week(\DateTimeImmutable $monday): string
    {
        return $this->range($monday, $monday);
    }

    /**
     * "<first Monday> – <Sunday of the last week>".
     */
    public function range(\DateTimeImmutable $firstMonday, \DateTimeImmutable $lastMonday): string
    {
        return $this->date($firstMonday) . ' – ' . $this->date($lastMonday->modify('+6 days'));
    }
}
