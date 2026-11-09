# Rules shared by every report command

Read this file first. It is the single source for projects, environments, people and date logic. It is used by the root commands in `ngcdf/.claude/commands/` and, through the copy `sync.sh` places in `<project>/.claude/report-template/`, by every project's own commands.

"Toolkit folder" below means the folder this file is in: `ngcdf/reports/_template/` for the root commands, `.claude/report-template/` inside a project.

## Projects and environments

Projects are the `*.ngcdf.go.ke` git repositories directly under the ngcdf root (folders that are not git repositories, such as `ai.ngcdf.go.ke`, are skipped). Folder, short name and display name:

| Folder | Short name | Display name |
|---|---|---|
| `smart.ngcdf.go.ke` | smart | Smart NG-CDF, the core fund management platform |
| `bursary.ngcdf.go.ke` | bursary | NG-CDF Bursary Portal (formerly studentportal) |
| `bursarywriter.ngcdf.go.ke` | bursarywriter | Bursary Writer (superseded by Smart Bursary Documents, October 2026) |
| `shif.ngcdf.go.ke` | shif | NG-CDF SHIF |
| `lms.ngcdf.go.ke` | lms | NG-CDF Learning Management System |
| `mp.ngcdf.go.ke` | mp | MP Dashboard |
| `citizen.ngcdf.go.ke` | citizen | Citizen Transparency Portal |
| `assets.ngcdf.go.ke` | assets | NG-CDF Assets (formerly NGCDF CATS) |
| `jobs.ngcdf.go.ke` | jobs | NG-CDF Careers, the recruitment portal |
| `tickets.ngcdf.go.ke` | tickets | NG-CDF Tickets, the central support desk |
| `oceo.ngcdf.go.ke` (repo `activities.ngcdf.go.ke`) | activities | NG-CDF OCEO (FAPM), the CEO's confidential field activity system. RESTRICTED: describe work by feature, never quote activity data |

A new `*.ngcdf.go.ke` repository is a project too: use the part before `.ngcdf.go.ke` as its short name.
Branch to environment: `main` = Production, `staging` = Staging, `devs` = Development (shared). `dev_<name>` = that developer's working branch (`dev_henry` = Henry, `dev_newton` = Newton, `dev_errol` = Errol, `dev_noah` = Noah, `dev_imbai` = Imbai, `dev_josh` = Josh, `dev_mulei` = Jimmy Mulei, `dev_joemwaks` = Joseph Mwakai, `dev_bernard_jerome` = Bernard Jerome, `dev_yvette` = Yvette, `dev_ninah` = Ninah, `dev_alex` = Alex Kamau, `dev_jerome` = Bernard Jerome, `newton_dev` = Newton). `dev_UI_fixes` is a shared branch (Bernard Jerome's financial statements work in November 2026), so name the commit author, not the branch. Any other branch: use its bare name.
Promotion flow: dev_<name> to devs to staging to main. Work on `main` is live. Work only on `devs` or `staging` is not live yet.

## People (canonicalise `%an <%ae>`)

| Person | Author names or emails to merge |
|---|---|
| Henry | Henry Manyonyi, henrymanyonyi, henrymanyonyi4, henrymanyonyi4@gmail.com |
| Newton | NewtonKamau, NewtonKamauNg, newton, kamaunewton78@gmail.com |
| Bernard Jerome | BernardJerome, Jerome Bernard, bjerome@ngcdf.go.ke |
| Jerome Mugita | Jerome Mugita (separate person from Bernard Jerome, tell apart by email) |
| Errol | ErrolKitsiiri, Errol-Kitsiiri, Errol Onzere Kitsiiri, ekitsiiri@ngcdf.go.ke (usually on `dev_toni`) |
| Imbai | imbai, Brian Imbai, bimbai@ngcdf.go.ke |
| Joseph Mwakai | Joe Mwakai, MwakaiJoseph, joemwaks, jmwakai@ngcdf.go.ke |
| Jimmy Mulei | jimmy mulei, jimisaro, jimmymulei250@gmail.com |
| Noah | ynoah, "Your Full Name" with an Ynoah@ email |
| Josh | commits on `dev_josh` |
| Ninah | dev_ninah, aadero@ngcdf.go.ke (branch `dev_ninah`) |
| Alex Kamau | Kamaa21, akamau@ngcdf.go.ke (branch `dev_alex`) |

Anyone else: use their name as written. Skip bots (Dependabot, GitHub Actions).

## Date logic

**Since the last run** (the default for `/all-reports`, and accepted by every command as `since last run`): run `<toolkit folder>/last-run.sh`. It prints, for the root set and each project, the date of the last report and `start=`, the day after it (or the first commit for a project never reported). The period runs from that start to today. Each project uses its own start, so a project last reported earlier covers more. Root reports run from the root start to today, so no week is reported twice; a project last reported earlier is covered from its own start in its project reports, and the root reports note that in one line.

Weeks run Saturday to Friday. On a Friday use the current week, on any other day use the previous completed week, unless the user gives dates. Daily reports cover "yesterday" unless a date is given (on Monday, cover Friday to Sunday). State the period at the top of your reply. Accept arguments such as `2026-09-01..2026-09-14`, `last week`, `this month`, `September`, and compute the dates yourself.

## Getting the data

Run `<toolkit folder>/collect.sh <start> <end> [project-dir ...]` (add `--no-fetch` if fetched minutes ago). From the root it covers every project; inside a project it covers that project only. It fetches each repo and prints, per project: commits landed on Production, Staging and Development, the work waiting to be promoted, tags, every commit with author and branch, and totals. Read that output rather than running many separate git commands.

## Filtering and grouping

Drop merge commits, casing fixes, label tweaks, WIP, dependency bumps with no code change, and empty fixups. Keep features, business-logic fixes, security, compliance, integrations, performance and significant refactors. Collapse related commits into one item, never one item per commit. Commits made only to keep tooling in order (`.claude` commands, `.gitignore`) go in a single "housekeeping" line at most.

## Output locations

Root reports are saved in `reports/` as `YYYY-MM-DD-<type>-<scope>.pdf`, where the date is the last day of the period, for example `2026-09-18-daily-all-projects.pdf`, `2026-09-18-weekly-all-projects.pdf`, `2026-09-18-team-all-projects.pdf`, `2026-09-18-consolidated.pdf`, `2026-09-18-management.pdf`, `2026-09-18-release-testing-consolidated.pdf`.

Root reports also include `YYYY-MM-DD-release-notes-all-projects.pdf`, `-release-readiness-all-projects.pdf`, `-branch-health.pdf` and `-contract-changes.pdf`.

Project reports are saved in the project's `dev_updates_report/` with these names: `YYYY-MM-DD-daily-dev-report.pdf`, `-weekly-dev-report.pdf`, `-team-dev-report.pdf`, `-release-testing-report.pdf`, `-consolidated-report.pdf`, `-management-report.pdf`, `-release-notes.pdf`, `-release-readiness.pdf`, `-branch-health.pdf`, `-contract-changes.pdf`. When made from the root, the same file also goes to `reports/projects/<short-name>/`. `last-run.sh` reads these names, so keep them exact. If the file exists, add `-2`, `-3`. Tell the user the path of every PDF.

## Report types

| Type | Audience | Content |
|---|---|---|
| daily | the team at standup | yesterday's work (Monday: Friday to Sunday) |
| weekly | engineering | the period's work by project and theme |
| team | engineering lead | the period's work by developer |
| consolidated | engineering lead | everything: environments, promotion gaps, people, risks |
| management | non-technical manager | outcomes in plain words, owners, what is live |
| release testing | QA team | what to test, where, on which environment |
| release notes | end users (FAMs, NSCAs, HODs, officers, applicants, MPs) | what changed for them in Production, in plain words, by role |
| release readiness | team lead and release approver | go or no go for a Staging to Production release: contents, data changes, sign off, rollback |
| branch health | team lead | work stuck on branches, idle time, drift, promotion gaps, local state |
| contract changes | owners of connected apps | changes to APIs, webhooks and shared config between projects, and whether each consumer kept up |

`/all-reports` produces every type.

## Helper scripts (toolkit folder)

| Script | Gives |
|---|---|
| `collect.sh <start> <end>` | commits per environment, promotion gap, every commit in the period |
| `last-run.sh [--type <name>]` | last report date and next start, overall or for one report type (for example `--type release-notes`) |
| `branches.sh` | branches with work not on the shared branch, ahead and behind counts, days idle, authors, local state (report toolkit files are listed apart; never report them as uncommitted work) |
| `release-diff.sh [--from origin/main] [--to origin/staging]` | what a release carries: commits, migrations (and risky statements in them), seeders, config and env, routes, API surface, jobs and mail, tests |
| `contracts.sh <start> <end>` | commits touching API, webhook, contract and shared config paths, with the environment each reached, and the env keys naming other projects |

## Contracts between projects

| Provider | Consumer | Contract |
|---|---|---|
| smart | lms, mp | identity and data API (`SMART_API_BASE_URL`, `SMART_AUTH_API_KEY`, `SMART_MP_API_KEY`) |
| smart | bursary | Geo Reference Data API, bursary call resync, applicant sync (legacy `studentportal` names) |
| bursary | smart | Institutions Registry API, applicant and document links |
| smart and shif | each other | `SMART_NGCDF_BASE_URL`, KYC and beneficiary endpoints (`shif.ngcdf.go.ke/doks/SHIFNGCDF_BUILD_BRIEF.md`) |
| smart | citizen | public aggregate API (`citizen.ngcdf.go.ke/docs/SMART_NGCDF_API_CONTRACT.md`) |
| smart | tickets | signed v2 HMAC API and `ticket.updated` webhook (`tickets.ngcdf.go.ke/docs/API.md`) |
| assets, jobs, activities | none | standalone; activities has no integration by design |

## Database safety in readiness and rollback advice

Never advise `migrate:rollback`, `migrate:fresh`, `migrate:refresh`, `db:wipe` or any drop against a real database. A rollback plan is: take a database backup before migrating, and if the release must be undone, redeploy the previous Production commit and restore that backup. An edited migration that already ran elsewhere is a red flag: say so and name it.
