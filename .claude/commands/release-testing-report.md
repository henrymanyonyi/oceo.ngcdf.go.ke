---
description: Release testing report for the QA team on NG-CDF OCEO (FAPM), the CEO's confidential field activity planning and monitoring system, the fixes, enhancements and features ready to test and on which environment (PDF in dev_updates_report/)
argument-hint: "[dates, 'last week' or 'since last run', default: last completed Sat to Fri week] [--staging-only]"
---
Generate the **release testing report** for the testing team on `oceo.ngcdf.go.ke`: what has been built and is ready to test, and where. Arguments: $ARGUMENTS.

The audience is testers, not developers. Be specific about where to find a change, who can use it and what to try. No code, file names or commit hashes in the PDF.

## Shared rules for this project's reports

- Run from the project root (`oceo.ngcdf.go.ke`). The toolkit is `.claude/report-template/`: read its `RULES.md` (people, environments, date logic, filtering, file names) and `STYLE.md` (PDF building blocks and voice) first and follow them.
- Collect with `.claude/report-template/collect.sh <start> <end>` (this project only; it fetches the repo, add `--no-fetch` if fetched minutes ago). Read its output instead of running many git commands; use `git show --stat <hash>` where a message is unclear.
- `since last run` as the period: run `.claude/report-template/last-run.sh` and use its `start=` to today.
- Branches: `main` = Production, `staging` = Staging, `devs` = Development (shared), `dev_<name>` = that developer's branch, not deployed. A missing branch means that environment does not exist for this project.
- Write the body as an HTML fragment in the session scratchpad (or `/tmp`), render with `.claude/report-template/render.sh`, save the PDF to `dev_updates_report/` (create it if missing), then delete the scratch file. If the PDF name exists, add `-2`, `-3`.
- The file date is the last day of the period. Tell the user the PDF path.
- RESTRICTED. Describe work by feature and role only. Never put activity titles, places, officers named in records, costs or any record data in a report (developers are named as usual). If the repo has no staging or devs branch, say Production is the only environment.

## Steps

1. Work out the period (Saturday to Friday unless given) and state it at the top of your reply. The promotion gap is always measured as of now.
2. Collect for the period, then list the real "ready to test" work, even if older than the period:
   - On Staging, not yet on Production: `git log --no-merges --format="%h %ad %an %s" --date=short origin/main..origin/staging`
   - On Development, not yet on Staging: `git log --no-merges --format="%h %ad %an %s" --date=short origin/staging..origin/devs`
   - Landed on Production in the period: smoke checks. Tags in the period mark releases.
   - With `--staging-only`, cover only what has reached Staging (on Staging awaiting sign off, plus what passed through Staging to Production in the period for regression).
3. Testers' view of each environment: Development = early testing of new work; Staging = release candidate, full test, regression and sign off; Production = smoke check after release; `dev_<name>` branches are not deployed, list them only under "Coming later". Work is testable on the highest environment it has reached.
4. For each item (filter noise, collapse related commits) read the change and note: the screen, menu, route or API affected (routes, Livewire components, views, or React pages), which roles can reach it, migrations or seeders, config or `.env` needs, queue, scheduled job, email, SMS or PDF behaviour, whether automated tests were added (if not, manual testing matters more), and nearby features that could regress. Only state facts visible in the code, otherwise write "confirm with <developer>".
5. Sections, in order:
   - `stats` row: items to test, on Staging awaiting sign off, on Development, live on Production this period.
   - **Summary**: a `lead` paragraph of where to focus, then `points` bullets with the developer as a `badge`.
   - **Environment status**: a row each for Production, Staging, Development: what is there, when it last changed, what testers do. `status-live`, `status-pending`, `status-risk` chips.
   - **Test priorities**: highest-risk items first (money, permissions, data migrations, bulk actions, integrations) with environment, developer and a risk chip.
   - **What to test now**: `h3` per environment (Staging, Development, then Production smoke checks), numbered `items`: bold title, what changed, where to find it and who can use it, scenarios (normal path, edge cases, wrong-role access, bad input), setup needed, risk chip, developer to ask.
   - **Integration checks**: No outbound integration by design (FRD 12), except the email and SMS alerts. Check alert content carries only the reference and the step.
   - **Regression checklist**: existing features to re-check and why.
   - **Setup notes**: migrations, env values, seeders, queue workers to confirm per environment. Omit if none.
   - **Coming later**: work only on personal branches, with owner.
   - **Risks and gaps**: large unpromoted gaps, changes without tests, quiet environments. Facts only.
   If nothing needs testing, say so in a `lead` paragraph and skip the empty sections.
6. Render: `.claude/report-template/render.sh --title "Release Testing Report" --period "<start> to <end in words>" --subtitle "NG-CDF OCEO Testing" --body <scratch>.html --out "dev_updates_report/YYYY-MM-DD-release-testing-report.pdf"`.
7. Give the PDF path, a five-line summary of what testers should start with, and `Next report period: <next Saturday> to <next Friday>`.
