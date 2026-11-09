# Sourced by the toolkit scripts. Sets ROOT and PROJECTS from the script location and arguments:
# from ngcdf/reports/_template every git project (or the named ones), from <project>/.claude/report-template that project.
TK="$(cd "$(dirname "${BASH_SOURCE[1]}")" && pwd)"
if [ -d "$TK/../../.git" ]; then
  _P="$(cd "$TK/../.." && pwd)"; ROOT="$(dirname "$_P")"; PROJECTS=("$(basename "$_P")")
else
  ROOT="$(cd "$TK/../.." && pwd)"
  [ ${#PROJECTS[@]} -eq 0 ] && PROJECTS=($(cd "$ROOT" && for d in *.ngcdf.go.ke; do [ -d "$d/.git" ] && echo "$d"; done))
fi
has() { git -C "$1" rev-parse -q --verify "$2" >/dev/null; }
