---
description: Branch health for NG-CDF OCEO (FAPM), the CEO's confidential field activity planning and monitoring system, work stuck on branches, idle time, drift and promotion gaps (PDF in dev_updates_report/)
argument-hint: "[--stale-days 14]"
---
Produce the **branch health report** for `oceo.ngcdf.go.ke`. Arguments: $ARGUMENTS.

## Shared rules for this project's reports

- Run from the project root (`oceo.ngcdf.go.ke`). The toolkit is `.claude/report-template/`: read its `RULES.md` (people, environments, date logic, filtering, file names) and `STYLE.md` (PDF building blocks and voice) first and follow them.
- Collect with `.claude/report-template/collect.sh <start> <end>` (this project only; it fetches the repo, add `--no-fetch` if fetched minutes ago). Read its output instead of running many git commands; use `git show --stat <hash>` where a message is unclear.
- `since last run` as the period: run `.claude/report-template/last-run.sh` and use its `start=` to today.
- Branches: `main` = Production, `staging` = Staging, `devs` = Development (shared), `dev_<name>` = that developer's branch, not deployed. A missing branch means that environment does not exist for this project.
- Write the body as an HTML fragment in the session scratchpad (or `/tmp`), render with `.claude/report-template/render.sh`, save the PDF to `dev_updates_report/` (create it if missing), then delete the scratch file. If the PDF name exists, add `-2`, `-3`.
- The file date is the last day of the period. Tell the user the PDF path.
- RESTRICTED. Describe work by feature and role only. Never put activity titles, places, officers named in records, costs or any record data in a report (developers are named as usual). If the repo has no staging or devs branch, say Production is the only environment.

## Steps

1. Run `.claude/report-template/branches.sh`. Idle 14 days or more (or `--stale-days`) is **stale**; 30 days or more, or 100 or more commits behind, is **at risk**; a promotion gap older than 7 days is stale.
2. For each branch with work, say what it is (feature, fix, experiment, dependency updates) and whether it is already applied (`git cherry -v <shared> <branch>`, `-` lines are applied). Toolkit files (`.claude/`, `dev_updates_report/`) are never uncommitted work.
3. Sections: `stats` (branches with unmerged work, commits not on the shared branch, oldest idle days, promotion gap); **Needs a decision** (branch, owner, commits, idle days, what it is, suggested action, chip); **Promotion gaps** with oldest waiting date; **All branches** table (branch, owner, ahead, behind, last commit, idle, files); **Environments** missing; **This machine** (unpushed, unpushed local branches, uncommitted work files); **Suggested actions** with owners.
4. Render: `.claude/report-template/render.sh --title "Branch Health" --period "<today in words>" --subtitle "NG-CDF OCEO" --body <scratch>.html --out "dev_updates_report/YYYY-MM-DD-branch-health.pdf"`.
5. Give the PDF path and the top decisions.
