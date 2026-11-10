#!/usr/bin/env bash
# Collects git activity for a period from every NG-CDF project (or the named ones).
# Usage: collect.sh <start YYYY-MM-DD> <end YYYY-MM-DD> [--no-fetch] [project-dir-name ...]
# Output is plain text, one block per project, for the report commands to read.
# Run from ngcdf/reports/_template it covers every project (or the named ones). The copy that sync.sh
# places in <project>/.claude/report-template covers that project only.
set -uo pipefail
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
START="$1"; END="$2"; shift 2
FETCH=1; PROJECTS=()
for a in "$@"; do [ "$a" = "--no-fetch" ] && FETCH=0 || PROJECTS+=("$a"); done
if [ -d "$DIR/../../.git" ]; then
  # Project copy: <project>/.claude/report-template/collect.sh
  PROJ="$(cd "$DIR/../.." && pwd)"; ROOT="$(dirname "$PROJ")"; PROJECTS=("$(basename "$PROJ")")
else
  ROOT="$(cd "$DIR/../.." && pwd)"
  [ ${#PROJECTS[@]} -eq 0 ] && PROJECTS=($(cd "$ROOT" && for d in *.ngcdf.go.ke; do [ -d "$d/.git" ] && echo "$d"; done))
fi
NEXT="$(date -j -v+1d -f %Y-%m-%d "$END" +%Y-%m-%d 2>/dev/null || date -d "$END +1 day" +%Y-%m-%d)"

for p in "${PROJECTS[@]}"; do
  R="$ROOT/$p"
  echo "################ PROJECT: $p"
  [ -d "$R/.git" ] || { echo "(not a git repository, skipped)"; continue; }
  [ $FETCH -eq 1 ] && git -C "$R" fetch --all --prune -q 2>&1 | sed 's/^/fetch warning: /'
  echo "remote: $(git -C "$R" remote get-url origin 2>/dev/null)"
  echo "period: $START to $END"

  echo "--- ENVIRONMENTS (first-parent history incl. merges/PRs, period only)"
  for pair in "main:Production" "staging:Staging" "devs:Development"; do
    b="${pair%%:*}"; label="${pair##*:}"
    if git -C "$R" rev-parse -q --verify "refs/remotes/origin/$b" >/dev/null; then
      n=$(git -C "$R" log "origin/$b" --no-merges --format=%ad --date=short --after="$START" --before="$(date -j -v+3d -f %Y-%m-%d "$END" +%Y-%m-%d 2>/dev/null || date -d "$END +3 day" +%Y-%m-%d)" | awk -v s="$START" -v e="$END" '$1>=s && $1<=e' | wc -l | tr -d " ")
      echo "[$label / origin/$b] work commits landed: $n (last update: $(git -C "$R" log -1 --format='%ad' --date=short "origin/$b"))"
      git -C "$R" log "origin/$b" --first-parent --after="$START" --before="$NEXT" --format='  %h %ad %an | %s' --date=short | head -60
    else
      echo "[$label / origin/$b] branch does not exist"
    fi
  done
  echo "--- PROMOTION GAP (work not yet promoted, as of now)"
  for pair in "staging:main:Staging ahead of Production" "devs:staging:Development ahead of Staging"; do
    hi="${pair%%:*}"; rest="${pair#*:}"; lo="${rest%%:*}"; label="${rest#*:}"
    if git -C "$R" rev-parse -q --verify "origin/$hi" >/dev/null && git -C "$R" rev-parse -q --verify "origin/$lo" >/dev/null; then
      echo "[$label] $(git -C "$R" rev-list --count --no-merges "origin/$lo..origin/$hi") commits"
    fi
  done
  echo "--- TAGS IN PERIOD"
  git -C "$R" for-each-ref refs/tags --format='%(creatordate:short) %(refname:short)' | awk -v s="$START" -v e="$END" '$1>=s && $1<=e' | sed 's/^/  /'
  echo "--- ALL COMMITS IN PERIOD (no merges, by author date): hash date author <email> | source-ref | subject"
  LIST="$(git -C "$R" log --all --source --after="$(date -j -v-2d -f %Y-%m-%d "$START" +%Y-%m-%d 2>/dev/null || date -d "$START -2 day" +%Y-%m-%d)" --before="$(date -j -v+3d -f %Y-%m-%d "$END" +%Y-%m-%d 2>/dev/null || date -d "$END +3 day" +%Y-%m-%d)" --no-merges --format='%h %ad %an <%ae> | %S | %s' --date=short | awk -v s="$START" -v e="$END" '$2>=s && $2<=e' | sed -E 's#refs/(heads|remotes/origin)/##')"
  [ -n "$LIST" ] && echo "$LIST"
  echo "--- COMMIT COUNT: $( [ -n "$LIST" ] && echo "$LIST" | wc -l | tr -d ' ' || echo 0)"
  HASHES="$( [ -n "$LIST" ] && echo "$LIST" | awk '{print $1}' | sort -u)"
  echo "--- FILES CHANGED / LINES: $( [ -n "$HASHES" ] && echo "$HASHES" | xargs git -C "$R" show --no-walk --shortstat --format= 2>/dev/null | awk '/changed/{f+=$1;i+=$4;d+=$6}END{print f+0" files, +"i+0" / -"d+0}' || echo '0 files, +0 / -0')"
  echo
done
