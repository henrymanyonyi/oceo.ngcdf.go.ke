---
description: Release notes for the users of NG-CDF OCEO (FAPM), the CEO's confidential field activity planning and monitoring system, what changed in Production for them in plain words (PDF in dev_updates_report/)
argument-hint: "[period: 'since last notes' (default), dates, 'last release']"
---
Write **release notes for the people who use `oceo.ngcdf.go.ke`**. Arguments: $ARGUMENTS.

Users, not developers: say what they can now do, what works differently, what was fixed, and what they must do. No developer names, code, file names, branches, commit hashes or ticket codes.

## Shared rules for this project's reports

- Run from the project root (`oceo.ngcdf.go.ke`). The toolkit is `.claude/report-template/`: read its `RULES.md` (people, environments, date logic, filtering, file names) and `STYLE.md` (PDF building blocks and voice) first and follow them.
- Collect with `.claude/report-template/collect.sh <start> <end>` (this project only; it fetches the repo, add `--no-fetch` if fetched minutes ago). Read its output instead of running many git commands; use `git show --stat <hash>` where a message is unclear.
- `since last run` as the period: run `.claude/report-template/last-run.sh` and use its `start=` to today.
- Branches: `main` = Production, `staging` = Staging, `devs` = Development (shared), `dev_<name>` = that developer's branch, not deployed. A missing branch means that environment does not exist for this project.
- Write the body as an HTML fragment in the session scratchpad (or `/tmp`), render with `.claude/report-template/render.sh`, save the PDF to `dev_updates_report/` (create it if missing), then delete the scratch file. If the PDF name exists, add `-2`, `-3`.
- The file date is the last day of the period. Tell the user the PDF path.
- RESTRICTED. Describe work by feature and role only. Never put activity titles, places, officers named in records, costs or any record data in a report (developers are named as usual). If the repo has no staging or devs branch, say Production is the only environment.

## Steps

1. Period: by default since the last release notes, `.claude/report-template/last-run.sh --type release-notes` `start=` to today (never given notes before: use the start of the last report period from `last-run.sh`). Only work that **reached Production** counts: `git log origin/main --no-merges --after=<start>`; merges into `main` mark release dates. State the period.
2. Read each change from the user's side (views, menus, routes) to name the menu, screen and button, and the roles that see it. Leave out refactors, tests and tooling.
3. Sections: a `lead` paragraph with release dates; **New** (bold plain title, what users can do, "Where:" and "Who:"); **Changed** (rules and steps that now work differently); **Fixed** (by symptom); **What you need to do** (omit if none); **Coming soon** (only work already on Staging, no dates). Use `h3` per user role when there are several.
4. Render: `.claude/report-template/render.sh --title "Release Notes" --period "<start> to <end in words>" --subtitle "NG-CDF OCEO" --body <scratch>.html --out "dev_updates_report/YYYY-MM-DD-release-notes.pdf"`. Nothing new on Production: say so, no PDF.
5. Give the PDF path and the "What you need to do" text ready to paste. Send nothing anywhere unless asked.
