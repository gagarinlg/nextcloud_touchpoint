#!/usr/bin/env bash
# Live tracker for the crm_notes fix/review workflow (since /workflows TUI
# doesn't render in the VSCode extension). Usage: bash .claude/wf-watch.sh
set -u
WF_DIR="/home/gagarin/.claude/projects/-home-gagarin-code-notes/201b089e-4b17-4c99-96fa-7d77269a1f0f/subagents/workflows/wf_34d1226a-f10"
REPO="/home/gagarin/code/notes"

while true; do
  clear
  echo "=== crm_notes fix/review workflow @ $(date '+%H:%M:%S') ==="
  if [ ! -d "$WF_DIR" ]; then echo "workflow dir gone (finished/cleaned up)"; break; fi

  n=$(ls "$WF_DIR"/agent-*.jsonl 2>/dev/null | wc -l)
  echo "agents spawned so far: $n"
  echo "  (round 1: 1=fix 2=verify 3&4=reviews ; +4 per later round)"
  echo

  latest=$(ls -t "$WF_DIR"/agent-*.jsonl 2>/dev/null | head -1)
  if [ -n "${latest:-}" ]; then
    echo "--- most-recently-active agent: $(basename "$latest") ---"
    # last assistant text chunk from the transcript
    tail -c 20000 "$latest" | python3 -c "
import sys,json
last=''
for line in sys.stdin.read().splitlines():
    try:
        o=json.loads(line)
    except Exception:
        continue
    if o.get('type')=='assistant':
        msg=o.get('message',{})
        for c in msg.get('content',[]) if isinstance(msg,dict) else []:
            if isinstance(c,dict) and c.get('type')=='text' and c.get('text','').strip():
                last=c['text'].strip()
            if isinstance(c,dict) and c.get('type')=='tool_use':
                last='[tool: %s] %s' % (c.get('name'), str(c.get('input',{}))[:160])
print((last[:600]) if last else '(no text yet)')
" 2>/dev/null
  fi
  echo
  echo "--- repo files modified in last 2 min ---"
  find "$REPO/lib" "$REPO/src" "$REPO/tests" "$REPO/css" -type f -mmin -2 2>/dev/null | sed "s#$REPO/##" | head -20
  echo
  echo "(Ctrl-C to stop watching; the workflow keeps running regardless)"
  sleep 5
done
