---
description: Run every report type for NG-CDF OCEO (FAPM), the CEO's confidential field activity planning and monitoring system in one go, since the last run (daily, weekly, team, consolidated, management, release testing, release notes, release readiness, branch health, contract changes)
argument-hint: "[period: 'since last run' (default), dates, 'last week'] [--skip-daily] [--skip-management] [--signed-off 'item, item']"
---
Run the whole reporting set for `oceo.ngcdf.go.ke` in one go. Arguments: $ARGUMENTS.

## Shared rules for this project's reports

- Run from the project root (`oceo.ngcdf.go.ke`). The toolkit is `.claude/report-template/`: read its `RULES.md` (people, environments, date logic, filtering, file names) and `STYLE.md` (PDF building blocks and voice) first and follow them.
- Collect with `.claude/report-template/collect.sh <start> <end>` (this project only; it fetches the repo, add `--no-fetch` if fetched minutes ago). Read its output instead of running many git commands; use `git show --stat <hash>` where a message is unclear.
- `since last run` as the period: run `.claude/report-template/last-run.sh` and use its `start=` to today.
- Branches: `main` = Production, `staging` = Staging, `devs` = Development (shared), `dev_<name>` = that developer's branch, not deployed. A missing branch means that environment does not exist for this project.
- Write the body as an HTML fragment in the session scratchpad (or `/tmp`), render with `.claude/report-template/render.sh`, save the PDF to `dev_updates_report/` (create it if missing), then delete the scratch file. If the PDF name exists, add `-2`, `-3`.
- The file date is the last day of the period. Tell the user the PDF path.
- RESTRICTED. Describe work by feature and role only. Never put activity titles, places, officers named in records, costs or any record data in a report (developers are named as usual). If the repo has no staging or devs branch, say Production is the only environment.

## Steps

1. Period: by default **since the last run**, from `.claude/report-template/last-run.sh` `start=` to today. Dates or `last week` override it. The daily window is yesterday (on Monday, Friday to Sunday). State both at the top of your reply.
2. Fetch once: run `.claude/report-template/collect.sh <start> <today>`, then `branches.sh --no-fetch`, `release-diff.sh --no-fetch` and `contracts.sh <start> <today> --no-fetch`. Reuse their output for every report; do not fetch again. Release notes use their own period: `last-run.sh --type release-notes`.
3. Produce each report exactly as this project's own command describes (read `.claude/commands/<type>.md`), all into `dev_updates_report/`:

   | Report | File |
   |---|---|
   | Daily (daily window; skip with `--skip-daily` or if no work that day) | `YYYY-MM-DD-daily-dev-report.pdf` |
   | Weekly | `YYYY-MM-DD-weekly-dev-report.pdf` |
   | Team | `YYYY-MM-DD-team-dev-report.pdf` |
   | Consolidated | `YYYY-MM-DD-consolidated-report.pdf` |
   | Management (skip with `--skip-management`) | `YYYY-MM-DD-management-report.pdf` |
   | Release testing | `YYYY-MM-DD-release-testing-report.pdf` |
   | Release notes (only if something new reached Production) | `YYYY-MM-DD-release-notes.pdf` |
   | Release readiness (only if Staging is ahead of Production; pass `--signed-off` through) | `YYYY-MM-DD-release-readiness.pdf` |
   | Branch health | `YYYY-MM-DD-branch-health.pdf` |
   | Contract changes (only if a contract path changed) | `YYYY-MM-DD-contract-changes.pdf` |

   No qualifying commits in the period: no weekly, team, consolidated or management PDF, say "no activity". Release testing follows the promotion gap as of now, so it can still be due.
4. Finish with a table of every PDF created (path and commit or test item count), what was skipped and why, and that the next `/all-reports` picks up from today.
