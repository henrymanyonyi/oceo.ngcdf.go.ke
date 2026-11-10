# Deployment: automatic memo references and report compliance (9 November 2026)

## What changes

| Area | Change |
|---|---|
| Memo reference (REF NO) | Given automatically when a memo is first submitted, one sequence per department per financial year, for example `NG-CDF BOARD/ICT/001/2026-27` and `NG-CDF BOARD/HR/001/2026-27`. It stays with the memo for good, also after it is returned and resubmitted. Heads of Department no longer type it; the memo form says "Given automatically when you submit the memo". |
| Format | Settings › Thresholds › **Memo reference format**. `{unit}` is the department's code (`departments.code`, not yet editable in Settings), or its initials when it has none ("Office of the CEO" becomes OCEO, "Human Resources" HR), `{seq}` the number (001, 002, ...), `{fy}` the financial year (2026-27). A change applies to new numbers only. |
| Older memos | Keep the reference typed before this release; it still prints as REF NO. Memos already submitted are not renumbered. |
| Overdue reports | A department with an activity report past its due date cannot submit a new memo. The submit popup lists each overdue activity (title, memo reference, dates, due date, working days overdue, status) with a link to it. Saving drafts is never blocked; a returned memo can always be resubmitted. |
| CEO override | On the memo's page the CEO sees **Allow this memo** with a required reason. It lets that one memo through, and is kept on the memo and in the audit trail. |

## Steps

1. **Back up** the production database.
2. Pull the code, then `composer install --no-dev --optimize-autoloader`.
3. `php artisan migrate --force`. One new migration, `2026_11_09_224648_add_memo_numbering_and_report_compliance`: a new `memo_sequences` table and five nullable columns on `activities` (`memo_number` with a unique index, `memo_numbered_at`, `compliance_override_by`, `compliance_override_reason`, `compliance_override_at`). No data is changed.
4. `php artisan db:seed --class=RolesAndPermissionsSeeder --force` to add the `compliance.override` permission to the CEO.
5. `npm ci && npm run build`, then `php artisan optimize:clear && php artisan optimize`, restart PHP-FPM and `php artisan queue:restart`.
6. Check the derived codes before the first memo is submitted: `php artisan tinker --execute 'App\Models\Department::all()->each(fn ($d) => print($d->name." => ".App\Services\MemoNumbering::unitCode($d).PHP_EOL));'`. Set `departments.code` where the initials are not wanted.

## Rollback

`php artisan migrate:rollback --step=1` is safe **only before any memo has been numbered**: once numbers are issued they are part of the official record and must not be dropped (CLAUDE.md, database safety). After that, roll back the code only; the new columns are nullable and older code ignores them.

## Check after deploying

- Settings › Thresholds shows **Memo reference format** with `NG-CDF BOARD/{unit}/{seq}/{fy}`.
- A Head of Department's memo form shows the reference as given automatically.
- `php artisan tinker --execute 'echo Spatie\Permission\Models\Role::findByName("ceo")->hasPermissionTo("compliance.override") ? "ok" : "missing";'` prints `ok`.
