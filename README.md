# WeeklyPlanningBundle for Kimai

[![CI](https://github.com/moui/WeeklyPlanningBundle/actions/workflows/linting.yaml/badge.svg)](https://github.com/moui/WeeklyPlanningBundle/actions/workflows/linting.yaml)

Weekly capacity planning inside Kimai. Team leads and admins assign each person's hours for
the week to projects, see how full every person, customer and project is, follow project
budgets as they are planned, and compare the plan with the hours actually recorded, live.
Kimai stays the source of truth for users, teams, customers, projects and timesheets; the
plugin only stores the plans, in three tables of the Kimai database.

## Features

**Main menu → Planning** (for users with the `weekly_planning` permission):

| Page | Route | What it does |
| --- | --- | --- |
| Weekly planning | `/{locale}/weekly-planning` | Pick a week and a person, edit project rows (hours + notes), set the week's status (draft / published / closed). |
| Weekly assignments | `/{locale}/weekly-planning/assignments` | One week's saved plans by person (capacity, assigned, remaining, % utilisation), by customer, by project (with budget forecast), and in detail. CSV export. |
| Weekly deviations | `/{locale}/weekly-planning/deviations` | Planned vs. actual for one week or a range (max 26 weeks), filtered by person, customer and status. Views: detail, by status, by person, by customer. CSV export. |

**Dashboard widget "My weekly plan"** for every user: their own plan for the current week
next to the hours they have recorded so far, the planning notes under each project, and any
unplanned projects at the end. It is added to the default dashboard; see
[Customised dashboards](#customised-dashboards) for users who already changed theirs.

### Capacity

A person's capacity is their expected hours for that Monday–Sunday week from Kimai's
`WorkingTimeService`, the same numbers as the *Working times* page: it respects locked
months, work start / last working day and holidays. Absences recorded in advance (any
plugin that adds working-time day addons, e.g. [AbsencesBundle](https://github.com/moui/AbsencesBundle))
are subtracted, so vacations and leave lower what can be planned. For users whose
working-time mode is *none*, Kimai would report 0, so the plugin falls back to the sum of
their `work_monday…work_sunday` preferences.

Capacity is stored as a snapshot with each saved plan, so later contract changes do not
rewrite how full a past week looked.

### Actual hours

Actual hours are read live from the timesheets on every request: there is no import or
recalculation step. Only finished timesheets count, and they are assigned to a week by the
timesheet's local date, so a late-evening entry never slips into the next week because of UTC.

### Budget forecast

For projects with a **time budget**, the planning editor shows under each row: budget,
spent, open hours, weekly run-rate, estimated exhaustion date and a traffic light. It warns
when the week's planned hours for that project (every person, saved plans plus the form)
exceed the open budget; it warns, it does not block. Projects with a budget also show their
open hours in the project picker. *Weekly assignments → By project* shows the same per
project.

- Spent / open: Kimai's own budget statistics, i.e. the project's budget bar in Kimai
  (billable time only; monthly budgets count the current month).
- Run-rate: billable hours per week, averaged over the last 4 complete weeks (empty weeks
  count as 0). Weeks left = open / run-rate; the date counts from the current Monday. For a
  monthly budget the date is only shown when it falls within the current month.
- Traffic light: red when exhausted or under 4 weeks left, yellow up to 8, green beyond,
  grey "No recent activity" with no billable hours in the window.

### Colours for recorded hours

Widget and *Weekly deviations*: red = more than planned, grey = less (including nothing
yet), green = exactly as planned, orange = hours on a project that was not planned.

### Rules

- **Landing week.** Opening the planning page without choosing a week lands on the first
  week that is still open: a published or closed current week is skipped in favour of the
  next draft (or not yet created) week, with a notice. Navigating with the week arrows always
  shows the chosen week.
- **Saving** rejects: a row without a project but with hours or notes, hours that are not a
  number or are ≤ 0, the same project twice, a project outside the planner's scope, an empty
  plan, assignments when capacity is 0, and a total above capacity. A total *below* capacity
  saves with a warning. A **closed** week is read-only.
- **Draft prefill.** When a person has no saved plan for the week, the editor is prefilled
  (not saved) from their actual hours of the previous calendar week, restricted to in-scope
  projects. If that week has no hours, the prefill comes from their most recent earlier
  saved plan. A message says which source was used.
- **Deviation status** (on hours rounded to 2 decimals): *Not planned* (planned 0,
  actual > 0), *Not executed* (planned > 0, actual 0), *On target*, *Over*, *Under*.
  % deviation is empty when nothing was planned.
- **Data integrity.** The tables have foreign keys to Kimai users and projects. Deleting a
  user or project in Kimai deletes their plans. Deleting a user who saved a plan only clears
  the "created/updated by" link. The customer is not stored on an allocation; it always comes
  from the project, so it cannot drift.

### Scope

Within the pages:

- **Admins** (`ROLE_ADMIN` / `ROLE_SUPER_ADMIN`) can plan every enabled user on every
  visible project of a visible customer. They see all stored history, including people and
  projects that are disabled or hidden today.
- **Team leads** can plan the enabled members of the teams where they are *team lead*, on
  projects where both the project and its customer either have no team restriction or are
  assigned to one of those teams. They only see stored rows for those people and projects.
- A team lead who leads no team gets an explanatory message and no data.

Every POST re-resolves the scope and re-validates the posted user and project ids, so a
crafted request cannot reach a person or project the pickers would not offer.

## Requirements

- Kimai 2.66 or later
- PHP 8.2 or later

## Installation

Extract or clone the plugin into `var/plugins/` of your Kimai installation. The directory
must be named `WeeklyPlanningBundle` (no version number):

```bash
cd /opt/kimai/var/plugins/
git clone https://github.com/moui/WeeklyPlanningBundle.git
```

Then clear the cache and create the plugin's tables. **Back up the database first.**

```bash
cd /opt/kimai
bin/console kimai:reload -n
bin/console kimai:bundle:weeklyplanning:install
```

The installer runs `doctrine:migrations:migrate` with
`Resources/config/doctrine_migrations.yaml`, which keeps its own version table
(`bundle_migration_weekly_planning`). Re-running it is safe: already-applied migrations are
skipped. Kimai core upgrades do not touch these tables.

### Docker

Mount or copy the plugin to `/opt/kimai/var/plugins/WeeklyPlanningBundle`, then run the
commands as `www-data`:

```bash
docker compose exec -u www-data kimai bin/console kimai:reload -n
docker compose exec -u www-data kimai bin/console kimai:bundle:weeklyplanning:install
```

Running as `www-data` matters: run as root, `kimai:reload` leaves a root-owned cache that
the web server cannot write to.

## Configuration

There is nothing to configure. Who can use the pages is controlled by the permission below
and by Kimai's teams (see [Scope](#scope)); capacity comes from each user's working-time
settings in Kimai.

### Customised dashboards

The widget is added to Kimai's *default* dashboard, which every user sees until they
customise theirs. A saved custom dashboard is left alone; users can add the widget from the
widget list, or you can append it to every customised dashboard once with
`kimai:weekly-planning:add-widget` (see [Console commands](#console-commands)).

## Permissions

| Permission | Default roles | Grants |
| --- | --- | --- |
| `weekly_planning` | `ROLE_TEAMLEAD`, `ROLE_ADMIN`, `ROLE_SUPER_ADMIN` | The *Planning* menu and its three pages. What each user sees inside them is limited by [Scope](#scope). |

The *My weekly plan* widget needs no permission: it only shows the signed-in user's own plan.
Permissions can be changed under *System → Roles*.

## Console commands

| Command | What it does |
| --- | --- |
| `kimai:bundle:weeklyplanning:install` | Creates or updates the plugin's tables. Safe to re-run. |
| `kimai:weekly-planning:add-widget [--apply]` | Appends the widget to every enabled user's customised dashboard that does not have it yet. Without `--apply` it only reports. Re-running is harmless. |

## Translations

The plugin ships with English (`en`, the fallback) and Spanish (`es`). Translations are
XLIFF 1.2 files in `Resources/translations/` (`messages` and `flashmessages` domains). To
add a language, copy the `.en.xlf` files to `.<locale>.xlf`, translate the `<target>`
elements and run `bin/console kimai:reload -n`. Pull requests with new languages are welcome.

## Uninstall

Remove the plugin directory and reload. The tables stay; drop them manually if they are no
longer needed:

```sql
DROP TABLE kimai2_weekly_planning_allocations, kimai2_weekly_planning_plans,
           kimai2_weekly_planning_periods, bundle_migration_weekly_planning;
```

## Contributing

Bug reports, translations and pull requests are welcome. See [CONTRIBUTING.md](CONTRIBUTING.md).

## License

MIT. See [LICENSE](LICENSE).
