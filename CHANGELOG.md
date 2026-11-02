# Changelog

## 0.3.0

- First public release: translations (English, Spanish), MIT license, CI linting
  (php-cs-fixer, PHPStan), contributing guide.
- All UI text goes through Kimai's translator (`messages` and `flashmessages` domains):
  menu, page titles, widget, flash messages, validation errors, CSV headers, and the
  strings of the planning editor's inline script.
- Dates and week ranges follow the user's locale (Kimai's short date format) instead of a
  fixed `dd/mm/yyyy`.
- The `view` query parameter of *Weekly assignments* and *Weekly deviations* uses English
  values (`person`, `customer`, `project`, `status`, `detail`); old bookmarks fall back to
  the default view.
- Removed the one-off `kimai:weekly-planning:import-legacy` command that copied plans from
  an earlier external planning database.

## 0.2.1

- Weekly capacity subtracts absences (AbsencesBundle, or any working-time day addon), so
  vacations and leave recorded in advance lower what can be planned.

## 0.2.0

- Budget forecast where hours are assigned:
  - in *Weekly planning*, each row of a project with a time budget shows budget,
    spent, open hours, weekly run-rate, estimated exhaustion date and a traffic light,
    and warns when the week's planned hours for the project (all people) exceed what is
    left. Projects with a budget show the open hours in the picker.
  - *Weekly assignments → By project* adds "Planned (everyone)", "Budget left", "Rate",
    "Runs out" and "Risk".
  - Spent/open hours are Kimai's own budget statistics (billable time, monthly budgets
    respected); run-rate is the billable average of the last 4 complete weeks; traffic
    light: red < 4 weeks or exhausted, yellow ≤ 8, green otherwise.
- "My weekly plan" widget redesigned like Kimai's own "My projects": project and customer
  with their colour dots, the planning **notes** as a full-width block under each project
  (long notes wrap, line breaks kept), the colour legend in the card footer, and a
  recorded-vs-planned progress bar with "N% done (H:MM to go / H:MM over)".
- Recorded-hours colours, in the widget and on *Weekly deviations*: red = over plan,
  grey = under (including not executed), green = on target, orange = not planned.

## 0.1.1

- Opening *Weekly planning* without a week now lands on the first week still open:
  if the current week is published or closed it skips ahead to the next draft (or
  not-yet-created) week and says so. Week navigation (`?week=`) is unchanged.
- `kimai:weekly-planning:add-widget [--apply]` appends the widget to dashboards users
  already customised.

## 0.1.0

- First release: *Weekly planning*, *Weekly assignments* and *Weekly deviations* pages
  for Kimai 2.66.
- Three plugin tables (`kimai2_weekly_planning_periods`, `_plans`, `_allocations`) with
  foreign keys to Kimai users and projects, installed by
  `kimai:bundle:weeklyplanning:install` (own migration version table).
- Actual hours and deviations are computed live from timesheets; nothing is snapshotted.
- `weekly_planning` permission for `ROLE_TEAMLEAD`, `ROLE_ADMIN`, `ROLE_SUPER_ADMIN`,
  with team-lead scoping by Kimai team membership.
- "My weekly plan" dashboard widget for every user.
