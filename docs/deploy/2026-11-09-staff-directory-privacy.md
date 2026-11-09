# Deployment: staff directory privacy (9 November 2026)

## What changes

| Who | Staff list | Choosing officers |
|---|---|---|
| CEO, System Administrator | The full list, with search by name, PF number or email (new permission `staff.directory`). | Unchanged. |
| Chief of Staff, Assistant | Nothing is listed until they type two or more letters of a name or PF number. No search by email. Position and access changes work as before on the officer found. | The Office's team picker on the activity page is unchanged. |
| Head of Department | Their own department's officers and the officers they added. No department filter. | Team: their own department is listed; an officer of another department is found by name or PF number. Delegating a memo: officers of their own department only (also refused by the service). |
| Delegated officer (link, no account) | None. | Search by name or PF number only. |

Enforced on the database query in `App\Services\StaffVisibility`, so a department in the URL, a crafted search or a direct component call shows nothing more.

## Steps

1. Pull the code, `composer install --no-dev --optimize-autoloader`.
2. No migration.
3. `php artisan db:seed --class=RolesAndPermissionsSeeder --force` to add `staff.directory` to the CEO and the System Administrator. **Until this runs, the CEO and the Administrator also see the restricted views.**
4. `php artisan optimize:clear && php artisan optimize`, restart PHP-FPM.

## Rollback

Code only. The permission left in the database is harmless to older code.

## Check after deploying

- Signed in as the Chief of Staff, the staff list says "Search for an officer" until a name is typed.
- Signed in as a Head of Department, the staff list shows only their department, without a department filter.
