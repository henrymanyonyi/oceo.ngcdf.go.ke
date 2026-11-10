---
description: Weekly development report for NG-CDF OCEO (FAPM), the CEO's confidential field activity planning and monitoring system, the period's work by theme (PDF in dev_updates_report/)
argument-hint: "[dates, 'last week' or 'since last run', default: last completed Sat to Fri week]"
---
Generate the **weekly development report** for `oceo.ngcdf.go.ke`, covering every contributor. Arguments: $ARGUMENTS.

## Shared rules for this project's reports

- Run from the project root (`oceo.ngcdf.go.ke`). The toolkit is `.claude/report-template/`: read its `RULES.md` (people, environments, date logic, filtering, file names) and `STYLE.md` (PDF building blocks and voice) first and follow them.
- Collect with `.claude/report-template/collect.sh <start> <end>` (this project only; it fetches the repo, add `--no-fetch` if fetched minutes ago). Read its output instead of running many git commands; use `git show --stat <hash>` where a message is unclear.
- `since last run` as the period: run `.claude/report-template/last-run.sh` and use its `start=` to today.
- Branches: `main` = Production, `staging` = Staging, `devs` = Development (shared), `dev_<name>` = that developer's branch, not deployed. A missing branch means that environment does not exist for this project.
- Write the body as an HTML fragment in the session scratchpad (or `/tmp`), render with `.claude/report-template/render.sh`, save the PDF to `dev_updates_report/` (create it if missing), then delete the scratch file. If the PDF name exists, add `-2`, `-3`.
- The file date is the last day of the period. Tell the user the PDF path.
- RESTRICTED. Describe work by feature and role only. Never put activity titles, places, officers named in records, costs or any record data in a report (developers are named as usual). If the repo has no staging or devs branch, say Production is the only environment.

## Steps

1. Work out the period (Saturday to Friday unless given) and state it at the top of your reply.
2. Collect for the period.
3. Build: a `stats` row (commits landed, contributors, changes on Production, files changed), a `lead` paragraph of the period, `points` bullets with the owner as a `badge`, then `h2` sections by module or theme holding numbered `items` (collapse related commits, never one item per commit), each ending with who did it and the highest environment it reached. Close with a short environment line (what is on Production, Staging, Development) and the promotion gap.
4. Render: `.claude/report-template/render.sh --title "Weekly Development Report" --period "<start> to <end in words>" --subtitle "NG-CDF OCEO Engineering" --body <scratch>.html --out "dev_updates_report/YYYY-MM-DD-weekly-dev-report.pdf"`.
5. Give the PDF path and print `Next report period: <next Saturday> to <next Friday>`.
