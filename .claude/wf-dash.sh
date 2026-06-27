#!/usr/bin/env bash
# Live auto-refreshing dashboard for the touchpoint fix/review workflow.
# Redraws on any filesystem change in the workflow dir / repo (event-driven via
# inotifywait), with a 5s timeout so the elapsed clocks keep ticking. Falls back
# to plain 5s polling if inotifywait is unavailable.  Ctrl-C to stop.
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DASH="$HERE/wf-dashboard.py"
WF_DIR="/home/gagarin/.claude/projects/-home-gagarin-code-notes/201b089e-4b17-4c99-96fa-7d77269a1f0f/subagents/workflows/wf_34d1226a-f10"
REPO="/home/gagarin/code/notes"

watch_paths=("$WF_DIR")
for s in lib src tests css appinfo templates; do [ -d "$REPO/$s" ] && watch_paths+=("$REPO/$s"); done

while true; do
  clear
  python3 "$DASH"
  echo
  echo -e "\033[2m(live — refreshes on change, max 5s · Ctrl-C to stop)\033[0m"
  if command -v inotifywait >/dev/null 2>&1; then
    inotifywait -q -r -t 5 -e modify,create,delete,move "${watch_paths[@]}" >/dev/null 2>&1
  else
    sleep 5
  fi
done
