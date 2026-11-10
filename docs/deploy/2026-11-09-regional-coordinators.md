# Deployment: Regional Coordinators (9 November 2026)

A sixth role, approved by the CEO: **Regional Coordinator**, one per region. Record the approval in the next FRD revision.

## What changes

| Area | Change |
|---|---|
| The role | A Regional Coordinator prepares their region's **workplan** (Budgeting › Regional workplan) and its **activity memos**, and follows them to the CEO's decision. They see nothing else: not their department's own memos or budget, not another region's, not the register, dashboards, staff list or reports. |
| Workplans | A region's workplan works like a department's: typed in or imported from the workplan template, with an optional workplan document, submitted to the CEO, reviewed, approved, adjusted. One per region per year, under the coordinator's department. The Office of the CEO sees the regions after the departments on Department budgets. |
| Memos | A coordinator's memo must name the **workplan activity** it carries out (a line of the region's approved workplan); choosing it fills in the title, purpose and outputs not yet written. A line of another region's workplan or of a department's own budget is refused. A second memo on the same workplan activity shows a warning (not a block). Routes are the Heads': through the Assistant or Chief of Staff, or directly to the CEO. Alerts, email and SMS go to the coordinator. |
| References | A region has its own sequence: `NG-CDF BOARD/RC/CST/001/2026-27`, from the region's code in the geography data: CST Coast, NEA North Eastern, UEA Upper Eastern, LEA Lower Eastern, CNT Central, NRFT North Rift, SRFT South Rift, WES Western, NYA Nyanza, NRB Nairobi. |
| Overdue reports | A region is held back only by its own overdue activity reports; its department only by its own. |
| Budget position | A coordinator sees their region's workplan position; a Head of Department sees their department's own, without its regions. Board-wide figures (dashboards, Department costs, the budget position report) still count a region's money under its department. |
| Team | A coordinator browses the officers at their region's duty stations; others by name or PF number. |
| Accounts | Appoint a coordinator from **Staff list › Position or access › Regional Coordinator**: the region is the officer's duty station's region, so set a regional duty station first. One active coordinator per region. User accounts lists them (contact, password link, two-factor, deactivate). |

## Steps

1. **Back up** the production database.
2. Pull the code, `composer install --no-dev --optimize-autoloader`.
3. `php artisan migrate --force`. One new migration, `..._add_regional_coordinators`: nullable `users.region_id`, `activities.originating_region_id` and `department_budgets.region_id`. Existing rows are untouched (all empty, so every existing memo and budget stays a department's).
4. `php artisan db:seed --class=RolesAndPermissionsSeeder --force` to create the `regional_coordinator` role. **Run it once, after the code is deployed**: the seeder removes any role not in the code's matrix.
5. `npm ci && npm run build`, then `php artisan optimize:clear && php artisan optimize`, restart PHP-FPM, `php artisan queue:restart`.
6. For each region: make sure the coordinator is on the staff list with a regional duty station and an email, then appoint them (Staff list › Position or access), or from the server:
   `php artisan activities:create-user regional_coordinator rc.coast@ngcdf.go.ke "Full Name" --region=CST --department="Programmes" --staff=PF1234`

## Rollback

Code only, once any coordinator account, regional memo or regional workplan exists (they are part of the record). `php artisan migrate:rollback --step=1` is safe only before that. Rolling the code back while regional rows exist would show regional memos to the department's Head again, so do not.

## Check after deploying

- `php artisan tinker --execute 'echo Spatie\Permission\Models\Role::where("name", "regional_coordinator")->exists() ? "ok" : "missing";'` prints `ok`.
- Signed in as a coordinator: the menu shows Activity memos and Regional workplan only.
- Department budgets (Office of the CEO) lists the regions after the departments.
