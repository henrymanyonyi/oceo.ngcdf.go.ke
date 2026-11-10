---
description: Release readiness (go or no go) for promoting NG-CDF OCEO (FAPM), the CEO's confidential field activity planning and monitoring system from Staging to Production (PDF in dev_updates_report/)
argument-hint: "[--from origin/main] [--to origin/staging] [--signed-off 'item, item'] [--blocked 'item']"
---
Prepare the **release readiness report** for `oceo.ngcdf.go.ke`: a recommendation with reasons for whoever approves the release. The decision is the user's. Arguments: $ARGUMENTS.

## Shared rules for this project's reports

- Run from the project root (`oceo.ngcdf.go.ke`). The toolkit is `.claude/report-template/`: read its `RULES.md` (people, environments, date logic, filtering, file names) and `STYLE.md` (PDF building blocks and voice) first and follow them.
- Collect with `.claude/report-template/collect.sh <start> <end>` (this project only; it fetches the repo, add `--no-fetch` if fetched minutes ago). Read its output instead of running many git commands; use `git show --stat <hash>` where a message is unclear.
- `since last run` as the period: run `.claude/report-template/last-run.sh` and use its `start=` to today.
- Branches: `main` = Production, `staging` = Staging, `devs` = Development (shared), `dev_<name>` = that developer's branch, not deployed. A missing branch means that environment does not exist for this project.
- Write the body as an HTML fragment in the session scratchpad (or `/tmp`), render with `.claude/report-template/render.sh`, save the PDF to `dev_updates_report/` (create it if missing), then delete the scratch file. If the PDF name exists, add `-2`, `-3`.
- The file date is the last day of the period. Tell the user the PDF path.
- RESTRICTED. Describe work by feature and role only. Never put activity titles, places, officers named in records, costs or any record data in a report (developers are named as usual). If the repo has no staging or devs branch, say Production is the only environment.

## Steps

1. Run `.claude/report-template/release-diff.sh` (default `--from origin/main --to origin/staging`). No commits in the range: say there is nothing to release, no PDF.
2. Check with evidence, as RULES.md "Database safety in readiness and rollback advice" requires:
   - each added migration and what it does; any **modified** existing migration is a red flag, name it and the commit;
   - each risky statement hit: open the migration and say whether it is in `up()` (affects the release, needs a backup and a check after) or `down()` (rollback only, never run on Production);
   - seeders to run, config and env keys Production needs, queue, schedule, mail or SMS changes, routes and API changes other projects call (contracts table in RULES.md);
   - automated tests per item;
   - QA status: items in `--signed-off` are signed off, `--blocked` are blocked, the rest "Awaiting QA". Never assume sign off;
   - hygiene: `.gitignore` hiding source folders, `dd(`, `dump(` or `ray(` added.
3. Recommendation: **Go**, **Go with conditions** (list them) or **No go** (list blockers: a blocked item, an unexplained edited migration, a data changing migration with no backup step, missing Production config).
4. Sections: recommendation `callout`; `stats` (items, migrations, signed off, awaiting QA); **What is in the release** (item, developer, tests, QA chip); **Data changes**; **Configuration and services**; **Cross-project impact**; **Deployment checklist** (backup, pull, `composer install --no-dev`, `php artisan migrate --force`, seeders, caches, restart workers, named smoke checks); **Rollback plan** (redeploy `git rev-parse --short origin/main` and restore the backup, never `migrate:rollback`); **Risks and open questions** with who to ask.
5. Render: `.claude/report-template/render.sh --title "Release Readiness" --period "<Production commit> to <Staging commit>, <today in words>" --subtitle "NG-CDF OCEO" --body <scratch>.html --out "dev_updates_report/YYYY-MM-DD-release-readiness.pdf"`.
6. Tell the user the recommendation, its conditions, and the PDF path.
