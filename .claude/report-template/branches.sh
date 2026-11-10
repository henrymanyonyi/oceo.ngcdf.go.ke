#!/usr/bin/env bash
# Branch health: work that has not reached the shared branch, its age and size, drift, and local state.
# Usage: branches.sh [--no-fetch] [project-dir ...]
set -uo pipefail
FETCH=1; PROJECTS=()
for a in "$@"; do [ "$a" = "--no-fetch" ] && FETCH=0 || PROJECTS+=("$a"); done
source "$(dirname "${BASH_SOURCE[0]}")/_projects.sh"
TODAY=$(date +%s)
age() { echo $(( (TODAY - $(git -C "$1" log -1 --format=%ct "$2")) / 86400 )); }
for p in "${PROJECTS[@]}"; do
  R="$ROOT/$p"; echo "################ PROJECT: $p"
  [ $FETCH -eq 1 ] && git -C "$R" fetch --all --prune -q 2>&1 | sed 's/^/fetch warning: /'
  base=origin/devs; has "$R" $base || base=origin/main
  echo "shared branch: $base (last change $(git -C "$R" log -1 --format=%ad --date=short $base))"
  for e in main staging devs; do has "$R" origin/$e || echo "missing environment branch: $e"; done
  echo "--- BRANCHES WITH WORK NOT ON $base: branch | commits ahead | behind | last commit date | days idle | authors | files changed"
  for b in $(git -C "$R" for-each-ref --format='%(refname:short)' refs/remotes/origin | grep -vE '/HEAD$|^origin$|/(main|staging|devs)$'); do
    n=$(git -C "$R" rev-list --count --no-merges $base..$b); [ "$n" -eq 0 ] && continue
    bh=$(git -C "$R" rev-list --count $b..$base)
    au=$(git -C "$R" log --no-merges --format=%an $base..$b | sort | uniq -c | sort -rn | awk '{c=$1;$1="";printf "%s%s(%s)", s, substr($0,2), c; s=", "}')
    fc=$(git -C "$R" diff --name-only $base...$b | wc -l | tr -d ' ')
    echo "${b#origin/} | $n | $bh | $(git -C "$R" log -1 --format=%ad --date=short $b) | $(age "$R" $b) | $au | $fc"
    git -C "$R" log --no-merges --format='    %h %ad %an | %s' --date=short $base..$b | head -8
  done
  echo "--- PROMOTION GAP"
  has "$R" origin/staging && echo "Staging ahead of Production: $(git -C "$R" rev-list --count --no-merges origin/main..origin/staging), oldest $(git -C "$R" log --reverse --no-merges --format=%ad --date=short origin/main..origin/staging | head -1)"
  has "$R" origin/devs && has "$R" origin/staging && echo "Development ahead of Staging: $(git -C "$R" rev-list --count --no-merges origin/staging..origin/devs), oldest $(git -C "$R" log --reverse --no-merges --format=%ad --date=short origin/staging..origin/devs | head -1)"
  ST="$(git -C "$R" status --porcelain)"
  WORK="$(echo "$ST" | grep -vE ' \.claude/|dev_updates_report' | grep -v '^$')"
  TOOL="$(echo "$ST" | grep -E ' \.claude/|dev_updates_report' | wc -l | tr -d ' ')"
  echo "--- THIS CHECKOUT: branch $(git -C "$R" branch --show-current), unpushed $(git -C "$R" rev-list --count @{u}..HEAD 2>/dev/null || echo 'no upstream'), uncommitted work files $( [ -n "$WORK" ] && echo "$WORK" | wc -l | tr -d ' ' || echo 0), report toolkit files changed $TOOL (ignore these, they come from sync.sh and the reports)"
  [ -n "$WORK" ] && echo "$WORK" | head -10 | sed 's/^/    /'
  echo "--- LOCAL BRANCHES NOT PUSHED"
  git -C "$R" for-each-ref --format='%(refname:short) %(upstream:short)' refs/heads | awk '$2==""{print "    "$1}'
  echo
done
