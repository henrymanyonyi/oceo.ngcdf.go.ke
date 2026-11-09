---
description: Daily development report for NG-CDF OCEO (FAPM), the CEO's confidential field activity planning and monitoring system, yesterday's work (PDF in dev_updates_report/)
argument-hint: "[date, default yesterday; on Monday Friday to Sunday]"
---
Generate the **daily development report** for `oceo.ngcdf.go.ke`. Arguments: $ARGUMENTS.

## Shared rules for this project's reports

- Run from the project root (`oceo.ngcdf.go.ke`). The toolkit is `.claude/report-template/`: read its `RULES.md` (people, environments, date logic, filtering, file names) and `STYLE.md` (PDF building blocks and voice) first and follow them.
- Collect with `.claude/report-template/collect.sh <start> <end>` (this project only; it fetches the repo, add `--no-fetch` if fetched minutes ago). Read its output instead of running many git commands; use `git show --stat <hash>` where a message is unclear.
- `since last run` as the period: run `.claude/report-template/last-run.sh` and use its `start=` to today.
- Branches: `main` = Production, `staging` = Staging, `devs` = Development (shared), `dev_<name>` = that developer's branch, not deployed. A missing branch means that environment does not exist for this project.
- Write the body as an HTML fragment in the session scratchpad (or `/tmp`), render with `.claude/report-template/render.sh`, save the PDF to `dev_updates_report/` (create it if missing), then delete the scratch file. If the PDF name exists, add `-2`, `-3`.
- The file date is the last day of the period. Tell the user the PDF path.
- RESTRICTED. Describe work by feature and role only. Never put activity titles, places, officers named in records, costs or any record data in a report (developers are named as usual). If the repo has no staging or devs branch, say Production is the only environment.

## Steps

1. Work out the date (yesterday by default; on Monday cover Friday to Sunday) and state it at the top of your reply.
2. Collect for that date or window.
3. Standup style: a `stats` row (commits landed, people active, changes on Production), a short `lead` paragraph, then numbered `items` split into Bug fixes, Features and Other, each with a `badge` or name for who did it. Add an "Environment movement" note only if something reached Staging or Production. If nothing qualifying happened, say so in chat and make no PDF.
4. Render: `.claude/report-template/render.sh --title "Daily Development Report" --period "<date in words>" --subtitle "NG-CDF OCEO Engineering" --body <scratch>.html --out "dev_updates_report/YYYY-MM-DD-daily-dev-report.pdf"`.
5. Give the PDF path and a three-line summary.

## Optional: ClickUp handoff

If the user also asks to move ClickUp tickets to review: use the ClickUp connector (ask them to connect it if missing), confirm the literal status names and the list once, show the tickets currently in the "Done" status and confirm before moving them to "Awaiting Review", then report what moved. Never move tickets without that confirmation.
