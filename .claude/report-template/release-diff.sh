#!/usr/bin/env bash
# What a release would carry: commits, migrations, seeders, config and env, routes, API surface, tests.
# Usage: release-diff.sh [--from origin/main] [--to origin/staging] [--no-fetch] [project-dir ...]
set -uo pipefail
FROM=origin/main; TO=origin/staging; FETCH=1; PROJECTS=()
while [ $# -gt 0 ]; do case "$1" in --from) FROM="$2"; shift 2;; --to) TO="$2"; shift 2;; --no-fetch) FETCH=0; shift;; *) PROJECTS+=("$1"); shift;; esac; done
source "$(dirname "${BASH_SOURCE[0]}")/_projects.sh"
for p in "${PROJECTS[@]}"; do
  R="$ROOT/$p"; echo "################ PROJECT: $p ($FROM..$TO)"
  [ $FETCH -eq 1 ] && git -C "$R" fetch --all --prune -q 2>&1 | sed 's/^/fetch warning: /'
  if ! has "$R" "$FROM" || ! has "$R" "$TO"; then echo "(branch missing, nothing to compare)"; echo; continue; fi
  n=$(git -C "$R" rev-list --count --no-merges $FROM..$TO); echo "commits: $n"
  [ "$n" -eq 0 ] && { echo; continue; }
  git -C "$R" log --no-merges --format='  %h %ad %an | %s' --date=short $FROM..$TO
  sec() { local t="$1"; shift; local out; out="$(git -C "$R" diff --name-status $FROM...$TO -- "$@")"; echo "--- $t"; [ -n "$out" ] && echo "$out" | sed 's/^/  /' || echo "  none"; }
  sec "MIGRATIONS (A added, M modified: modified migrations are a red flag)" database/migrations
  echo "--- RISKY STATEMENTS IN CHANGED MIGRATIONS (drop, rename, truncate, delete, change of existing columns)"
  risky="$(git -C "$R" diff $FROM...$TO -- database/migrations | grep -E '^\+' | grep -vE '^\+\+\+' | grep -niE 'drop(Column|IfExists|Index|Foreign|Unique)?\(|->drop|rename(Column)?\(|truncate|->delete\(|DB::statement|->change\(\)|DELETE FROM|UPDATE ' | head -40)"
  [ -n "$risky" ] && echo "$risky" | sed 's/^/  /' || echo "  none"
  sec "SEEDERS" database/seeders
  sec "CONFIG AND ENV" config .env.example 'config/*' composer.json package.json
  sec "ROUTES" routes
  sec "API SURFACE (controllers, resources, middleware, contracts, webhooks)" app/Http/Controllers/Api 'app/Http/Controllers/Api/*' app/Http/Resources app/Http/Middleware docs 'documentation/*api*' 'documentation/**/*api*' '*CONTRACT*' 'postman*' 'Postman*'
  sec "QUEUE, SCHEDULE, MAIL, NOTIFICATIONS" app/Jobs app/Console routes/console.php app/Mail app/Notifications
  sec "TESTS" tests
  echo "--- FILES / LINES: $(git -C "$R" diff --shortstat $FROM...$TO)"
  echo
done
