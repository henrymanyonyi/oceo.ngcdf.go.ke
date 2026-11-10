---
description: Consolidated report for NG-CDF OCEO (FAPM), the CEO's confidential field activity planning and monitoring system, everything in the period across environments, people and risks (PDF in dev_updates_report/)
argument-hint: "[dates, 'last week' or 'since last run', default: last completed Sat to Fri week]"
---
Generate the **consolidated report** for `oceo.ngcdf.go.ke`: the complete picture of the period. Arguments: $ARGUMENTS.

## Shared rules for this project's reports

- Run from the project root (`oceo.ngcdf.go.ke`). The toolkit is `.claude/report-template/`: read its `RULES.md` (people, environments, date logic, filtering, file names) and `STYLE.md` (PDF building blocks and voice) first and follow them.
- Collect with `.claude/report-template/collect.sh <start> <end>` (this project only; it fetches the repo, add `--no-fetch` if fetched minutes ago). Read its output instead of running many git commands; use `git show --stat <hash>` where a message is unclear.
- `since last run` as the period: run `.claude/report-template/last-run.sh` and use its `start=` to today.
- Branches: `main` = Production, `staging` = Staging, `devs` = Development (shared), `dev_<name>` = that developer's branch, not deployed. A missing branch means that environment does not exist for this project.
- Write the body as an HTML fragment in the session scratchpad (or `/tmp`), render with `.claude/report-template/render.sh`, save the PDF to `dev_updates_report/` (create it if missing), then delete the scratch file. If the PDF name exists, add `-2`, `-3`.
- The file date is the last day of the period. Tell the user the PDF path.
- RESTRICTED. Describe work by feature and role only. Never put activity titles, places, officers named in records, costs or any record data in a report (developers are named as usual). If the repo has no staging or devs branch, say Production is the only environment.

## Steps

1. Work out the period and state it at the top of your reply.
2. Collect for the period.
3. Sections, in order:
   - `stats` row: commits landed, contributors, changes on Production, commits waiting to be promoted.
   - **Summary**: a `lead` paragraph, then `points` bullets with owners as `badge`s.
   - **Environments**: a table with a row each for Production, Staging and Development: what reached it in the period (count and main themes) and when it last changed. Then **Waiting to be promoted** from the promotion gap, with `status-live` and `status-pending` chips.
   - **Work by theme**: `h3` per module or theme with numbered `items`, each naming the person and the environment reached.
   - **People**: table of contributor, commits, main themes.
   - **Risks and follow-ups**: stalled work, large unpromoted gaps, branches with work not yet on `devs`, uncommitted or unpushed work you can see. Facts visible in git only.
4. Render: `.claude/report-template/render.sh --title "Consolidated Report" --period "<start> to <end in words>" --subtitle "NG-CDF OCEO" --body <scratch>.html --out "dev_updates_report/YYYY-MM-DD-consolidated-report.pdf"`.
5. Give the PDF path and a five-line summary.
