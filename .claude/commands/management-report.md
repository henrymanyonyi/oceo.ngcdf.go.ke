---
description: Plain-language management report for NG-CDF OCEO (FAPM), the CEO's confidential field activity planning and monitoring system, outcomes and owners for a non-technical manager (PDF in dev_updates_report/)
argument-hint: "[period: dates, 'last week', 'this month', 'since last run', default: last completed week]"
---
Generate the **management report** for `oceo.ngcdf.go.ke`. Arguments: $ARGUMENTS.

Audience: a manager who is not a developer. Outcome-focused and non-technical, but complete: every significant piece of work, with a named owner. Say what people can now do, what was fixed, what is live and what is not.

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
2. Collect for the period; read `git show --stat` where a message is unclear so you describe the real effect.
3. Plain language only: no file names, class names, branch jargon, commit hashes or ticket codes. Environments are "live (Production)", "being tested (Staging)" and "in development".
4. Sections, in order:
   - `stats` row: work items delivered, people involved, items now live, items waiting in testing.
   - **Executive summary**: a `lead` paragraph (5 to 7 sentences) and `points` bullets with the responsible person as a `badge`.
   - **What was delivered**: a `p class="sub"` line with the system's plain-language purpose (NG-CDF OCEO (FAPM), the CEO's confidential field activity planning and monitoring system), then numbered `items` written as outcomes, each ending with who was responsible and a status chip (`status-live` live, `status-pending` in testing or development).
   - **Who did what**: table of person, main contributions in plain words, rough effort (light, moderate, heavy).
   - **Release status**: what is live, what is waiting in testing, what is still in development.
   - **Risks, blockers and decisions needed**: facts visible in the work, phrased as what the manager may need to know or decide. Say so if there are none.
   - **Next period**: only as far as the work in progress supports.
5. Render: `.claude/report-template/render.sh --title "Management Report" --period "<start> to <end in words>" --subtitle "NG-CDF OCEO" --body <scratch>.html --out "dev_updates_report/YYYY-MM-DD-management-report.pdf"`.
6. Give the PDF path, the three most important points, and anything you were unsure of. Do not send it anywhere unless asked.
