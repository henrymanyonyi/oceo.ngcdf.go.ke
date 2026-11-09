---
description: Contract changes for NG-CDF OCEO (FAPM), the CEO's confidential field activity planning and monitoring system, changes to APIs, webhooks and shared config with other projects, and whether the other side kept up (PDF in dev_updates_report/)
argument-hint: "[dates, 'last week' or 'since last run', default: last completed Sat to Fri week]"
---
Produce the **contract changes report** for `oceo.ngcdf.go.ke`: changes to what this project offers other projects and what it calls on them. Arguments: $ARGUMENTS.

## Shared rules for this project's reports

- Run from the project root (`oceo.ngcdf.go.ke`). The toolkit is `.claude/report-template/`: read its `RULES.md` (people, environments, date logic, filtering, file names) and `STYLE.md` (PDF building blocks and voice) first and follow them.
- Collect with `.claude/report-template/collect.sh <start> <end>` (this project only; it fetches the repo, add `--no-fetch` if fetched minutes ago). Read its output instead of running many git commands; use `git show --stat <hash>` where a message is unclear.
- `since last run` as the period: run `.claude/report-template/last-run.sh` and use its `start=` to today.
- Branches: `main` = Production, `staging` = Staging, `devs` = Development (shared), `dev_<name>` = that developer's branch, not deployed. A missing branch means that environment does not exist for this project.
- Write the body as an HTML fragment in the session scratchpad (or `/tmp`), render with `.claude/report-template/render.sh`, save the PDF to `dev_updates_report/` (create it if missing), then delete the scratch file. If the PDF name exists, add `-2`, `-3`.
- The file date is the last day of the period. Tell the user the PDF path.
- RESTRICTED. Describe work by feature and role only. Never put activity titles, places, officers named in records, costs or any record data in a report (developers are named as usual). If the repo has no staging or devs branch, say Production is the only environment.

## Steps

1. Work out the period. Run `.claude/report-template/contracts.sh <start> <end>`. Contracts for this project: No outbound integration by design (FRD 12), except the email and SMS alerts. Check alert content carries only the reference and the step. The full table is in RULES.md.
2. Open each listed commit and keep only real contract changes (routes, request or response fields, validation callers must satisfy, authentication or signing, webhook payloads, rate limits, env keys). Classify each **Breaking**, **Needs check** or **Additive**.
3. For each change, look at the other project's repository (a sibling folder under the ngcdf root, `git -C ../<project> log -S<field or route>`, its client classes, `.env.example`, API docs) and record: updated, not needed, or **not updated**.
4. Sections: `stats` (contract changes, breaking, needs check, other side not updated); **Summary**; **Action needed** table (other project, direction, change, class chip, environment reached here, other side status, who to ask); **Changes in detail**; **Coming later** (contract work on personal branches); **Documentation gaps**. No contract changes: say so in a `lead` paragraph.
5. Render: `.claude/report-template/render.sh --title "Contract Changes" --period "<start> to <end in words>" --subtitle "NG-CDF OCEO" --body <scratch>.html --out "dev_updates_report/YYYY-MM-DD-contract-changes.pdf"`.
6. Give the PDF path and every breaking or not updated item.
