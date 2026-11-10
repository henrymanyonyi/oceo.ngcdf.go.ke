#!/usr/bin/env bash
# Changes to the contracts between projects in a period: APIs, webhooks, shared config, contract docs.
# Usage: contracts.sh <start YYYY-MM-DD> <end YYYY-MM-DD> [--no-fetch] [project-dir ...]
set -uo pipefail
START="$1"; END="$2"; shift 2; FETCH=1; PROJECTS=()
for a in "$@"; do [ "$a" = "--no-fetch" ] && FETCH=0 || PROJECTS+=("$a"); done
source "$(dirname "${BASH_SOURCE[0]}")/_projects.sh"
NEXT="$(date -j -v+1d -f %Y-%m-%d "$END" +%Y-%m-%d 2>/dev/null || date -d "$END +1 day" +%Y-%m-%d)"
PATHS=(routes/api.php 'routes/api*' app/Http/Controllers/Api app/Http/Resources config/services.php .env.example 'src/api' 'src/types' 'src/services' '*CONTRACT*' '*contract*.md' '*API*.md' '*api*.md' '*webhook*' '*Webhook*' 'app/Services/*Client*.php' 'app/Services/**/*Client*.php' '*.postman_collection.json' 'app/Http/Middleware/*Api*' 'app/Http/Middleware/*Signature*' 'app/Http/Middleware/*Hmac*')
for p in "${PROJECTS[@]}"; do
  R="$ROOT/$p"; echo "################ PROJECT: $p"
  [ $FETCH -eq 1 ] && git -C "$R" fetch --all --prune -q 2>&1 | sed 's/^/fetch warning: /'
  b=origin/devs; has "$R" $b || b=origin/main
  echo "--- commits on $b touching contract paths, $START to $END: hash date author | highest environment | subject"
  out="$(git -C "$R" log $b --no-merges --after="$START" --before="$NEXT" --format='%h' -- "${PATHS[@]}")"
  [ -z "$out" ] && echo "  none"
  for h in $out; do
    env=Development; has "$R" origin/staging && git -C "$R" merge-base --is-ancestor $h origin/staging && env=Staging
    git -C "$R" merge-base --is-ancestor $h origin/main && env=Production
    echo "  $(git -C "$R" log -1 --format='%h %ad %an' --date=short $h) | $env | $(git -C "$R" log -1 --format=%s $h)"
    git -C "$R" show --name-status --format= "$h" -- "${PATHS[@]}" | sed 's/^/      /' | head -12
  done
  echo "--- contract work on personal branches (not on $b)"
  pb="$(git -C "$R" log --no-merges --remotes --not $b --after="$START" --before="$NEXT" --format='  %h %ad %an | %s' --date=short -- "${PATHS[@]}")"
  [ -n "$pb" ] && echo "$pb" || echo "  none"
  echo "--- OUTBOUND CALLS TO OTHER PROJECTS (env keys naming them, on the shared branch)"
  b=origin/devs; has "$R" $b || b=origin/main
  git -C "$R" show $b:.env.example 2>/dev/null | grep -E '^(SMART|STUDENTPORTAL|BURSARY|SHIF|LMS|MP|CITIZEN|TICKETS|VITE_API|BURSARYWRITER)[A-Z_]*=' | sed 's/=.*//; s/^/  /' || true
  echo
done
