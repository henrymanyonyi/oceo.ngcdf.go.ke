#!/usr/bin/env bash
# Prints when each report set last ran, so a run can start the day after it.
# Usage: last-run.sh            (from ngcdf/reports/_template: the root set and every project)
#        last-run.sh            (from <project>/.claude/report-template: that project only)
# Output lines: <scope> <short-name> last=<YYYY-MM-DD|none> start=<YYYY-MM-DD> first-commit=<YYYY-MM-DD>
# "start" is the day after the last report, or the first commit date when the project was never reported.
# Add --type <suffix> (for example --type release-notes) to find the last report of one type only.
set -uo pipefail
ONLY=""; [ "${1:-}" = "--type" ] && { ONLY="$2"; shift 2; }
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
short() { case "$1" in oceo.ngcdf.go.ke|activities.ngcdf.go.ke) echo activities;; *) echo "${1%%.ngcdf.go.ke}";; esac; }
next_day() { date -j -v+1d -f %Y-%m-%d "$1" +%Y-%m-%d 2>/dev/null || date -d "$1 +1 day" +%Y-%m-%d; }
latest() { ls "$@" 2>/dev/null | xargs -n1 basename 2>/dev/null | grep -oE '^20[0-9]{2}-[0-9]{2}-[0-9]{2}' | sort | tail -1; }
line() { # scope name projdir last
  local first; first="$( [ -n "$3" ] && git -C "$3" log --all --reverse --format=%ad --date=short 2>/dev/null | head -1)"
  local start="${4:+$(next_day "$4")}"; start="${start:-${first:-unknown}}"
  echo "$1 $2 last=${4:-none} start=$start first-commit=${first:-none}"
}
REPORT_GLOB='-weekly-dev-report.pdf -team-dev-report.pdf -release-testing-report.pdf -daily-dev-report.pdf -consolidated-report.pdf -management-report.pdf'
[ -n "$ONLY" ] && REPORT_GLOB="-$ONLY.pdf"
proj_last() { # projdir short [rootdir]
  local files=() g
  for g in $REPORT_GLOB; do files+=("$1"/dev_updates_report/*$g); [ -n "${3:-}" ] && files+=("$3"/reports/projects/"$2"/*$g); done
  latest "${files[@]}"
}
if [ -d "$DIR/../../.git" ]; then
  P="$(cd "$DIR/../.." && pwd)"; n="$(basename "$P")"
  line project "$(short "$n")" "$P" "$(proj_last "$P" "$(short "$n")")"
  exit 0
fi
ROOT="$(cd "$DIR/../.." && pwd)"
if [ -n "$ONLY" ]; then line root all "" "$(latest "$ROOT"/reports/*-"$ONLY"*.pdf)"; else line root all "" "$(latest "$ROOT"/reports/*-consolidated.pdf)"; fi
for P in "$ROOT"/*.ngcdf.go.ke; do
  [ -d "$P/.git" ] || continue
  n="$(basename "$P")"; s="$(short "$n")"
  line project "$s" "$P" "$(proj_last "$P" "$s" "$ROOT")"
done
