#!/usr/bin/env python3
"""Live dashboard for the crm_notes fix/review workflow.

Prints ONE full snapshot of the workflow state to stdout. The wrapper
(wf-dash.sh) clears the screen and re-runs this on every filesystem event
(or every 5s). Reads only the workflow's on-disk journal + agent transcripts.
"""
import json
import os
import sys
import time

WF_DIR = "/home/gagarin/.claude/projects/-home-gagarin-code-notes/201b089e-4b17-4c99-96fa-7d77269a1f0f/subagents/workflows/wf_34d1226a-f10"
REPO = "/home/gagarin/code/notes"

# ANSI
B, DIM, R, G, Y, RED, CYA, MAG = (
    "\033[1m", "\033[2m", "\033[0m", "\033[32m", "\033[33m", "\033[31m",
    "\033[36m", "\033[35m",
)

ROLE_SIGS = [
    ("SENIOR FULL-STACK DEVELOPER fixing", "FIX", "fix"),
    ("build/test verifier", "VERIFY", "verify"),
    ("GRUMPY, SENIOR FULL-STACK/BACKEND DEVELOPER reviewing", "DEV-REVIEW", "dev"),
    ("NITPICKY, GRUMPY, SENIOR UX DESIGNER", "UX-REVIEW", "ux"),
    ("NEXTCLOUD APP-STORE REVIEWER", "COMPLIANCE", "comp"),
]


def jload(path):
    out = []
    try:
        with open(path) as f:
            for line in f:
                line = line.strip()
                if not line:
                    continue
                try:
                    out.append(json.loads(line))
                except Exception:
                    pass  # ignore a partially-written trailing line
    except FileNotFoundError:
        pass
    return out


def first_user_text(events):
    for o in events:
        if o.get("type") == "user":
            m = o.get("message", {})
            c = m.get("content") if isinstance(m, dict) else None
            if isinstance(c, str):
                return c
            if isinstance(c, list):
                for b in c:
                    if isinstance(b, dict) and b.get("type") == "text":
                        return b.get("text", "")
    return ""


def classify(text):
    for sig, label, key in ROLE_SIGS:
        if sig in text:
            return label, key
    return "?", "?"


def agent_stats(events):
    out_tok = tools = 0
    last_activity = "(starting…)"
    for o in events:
        if o.get("type") != "assistant":
            continue
        m = o.get("message", {})
        if not isinstance(m, dict):
            continue
        u = m.get("usage", {})
        if isinstance(u, dict):
            out_tok += u.get("output_tokens", 0) or 0
        for b in m.get("content", []):
            if not isinstance(b, dict):
                continue
            if b.get("type") == "tool_use":
                tools += 1
                inp = json.dumps(b.get("input", {}))[:90]
                last_activity = f"[{b.get('name')}] {inp}"
            elif b.get("type") == "text" and b.get("text", "").strip():
                last_activity = b["text"].strip().replace("\n", " ")[:110]
    return out_tok, tools, last_activity


def hms(secs):
    secs = int(secs)
    if secs < 60:
        return f"{secs}s"
    if secs < 3600:
        return f"{secs // 60}m{secs % 60:02d}s"
    return f"{secs // 3600}h{(secs % 3600) // 60:02d}m"


def sev_counts(findings):
    c = {"CRITICAL": 0, "HIGH": 0, "MEDIUM": 0, "LOW": 0, "NITPICK": 0}
    for f in findings:
        if isinstance(f, dict):
            c[f.get("severity", "?")] = c.get(f.get("severity", "?"), 0) + 1
    return c


STALE_SECS = 90  # no journal result + no transcript write in this long = dead (killed by a stop/resume)


def main():
    now = time.time()
    if not os.path.isdir(WF_DIR):
        print(f"{Y}workflow dir gone — run finished/cleaned up.{R}")
        return

    journal = jload(os.path.join(WF_DIR, "journal.jsonl"))
    done_ids = {o.get("agentId") for o in journal if o.get("type") == "result"}

    # --- map agentId -> (role, events, start, last_mtime) over all transcripts
    agents = []
    for fn in os.listdir(WF_DIR):
        if not (fn.startswith("agent-") and fn.endswith(".jsonl")):
            continue
        path = os.path.join(WF_DIR, fn)
        aid = fn[len("agent-"):-len(".jsonl")]
        ev = jload(path)
        if not ev:
            continue
        label, key = classify(first_user_text(ev))
        meta = os.path.join(WF_DIR, f"agent-{aid}.meta.json")
        start = os.path.getmtime(meta) if os.path.exists(meta) else os.path.getmtime(path)
        mtime = os.path.getmtime(path)
        if aid in done_ids:
            status = "done"
        elif now - mtime < STALE_SECS:
            status = "run"
        else:
            status = "dead"  # killed by a TaskStop during a resume cycle
        agents.append({
            "id": aid, "label": label, "key": key, "events": ev,
            "start": start, "mtime": mtime, "status": status,
        })
    agents.sort(key=lambda a: a["start"])

    # ---------- collect review/verify results in journal order ----------
    role_of = {a["id"]: a["key"] for a in agents}
    rounds = {}           # round -> {dev/ux/comp: counts}
    verify_hist = []      # list of (build,lint,tests,nfail)
    verdicts = {"dev": "", "ux": "", "comp": ""}
    seen = {"dev": 0, "ux": 0, "comp": 0}
    for o in journal:
        if o.get("type") != "result":
            continue
        res = o.get("result")
        aid = o.get("agentId")
        k = role_of.get(aid, "?")
        if isinstance(res, dict) and "findings" in res and k in ("dev", "ux", "comp"):
            seen[k] += 1
            rnum = seen[k]
            rounds.setdefault(rnum, {})[k] = sev_counts(res["findings"])
            rounds[rnum].setdefault(k + "_n", len(res["findings"]))
            rounds[rnum][k + "_n"] = len(res["findings"])
            verdicts[k] = (res.get("verdict", "") or "").replace("\n", " ")
        elif isinstance(res, dict) and "buildOk" in res:
            verify_hist.append((res.get("buildOk"), res.get("lintOk"),
                                res.get("testsOk"), len(res.get("failures", []))))

    # logical round = completed review rounds (+1 if a new round is underway)
    completed_rounds = max(seen.values()) if any(seen.values()) else 0
    live = [a for a in agents if a["status"] == "run"]
    in_progress = bool(live)
    cur_round = completed_rounds + (1 if in_progress else 0)
    phase = "idle / done"
    if live:
        last = max(live, key=lambda a: a["mtime"])
        phase = {"fix": "Fix", "verify": "Verify", "dev": "Review",
                 "ux": "Review", "comp": "Review"}.get(last["key"], "?")

    # ---------- header ----------
    print(f"{B}{CYA}═══ crm_notes fix/review loop · {time.strftime('%H:%M:%S')} ═══{R}")
    print(f"{B}Round {cur_round}/8{R} · phase: {B}{phase}{R} · "
          f"{completed_rounds} review-rounds done · "
          f"{len([a for a in agents if a['status']=='run'])} live · "
          f"{len([a for a in agents if a['status']=='dead'])} dead(stopped)")
    print()

    # ---------- agents panel ----------
    print(f"{B}AGENTS{R}  {DIM}({G}●{R}{DIM} live  ○ done  {RED}✗{R}{DIM} dead/interrupted){R}")
    # show all live + done, but collapse dead into a count unless recent
    for a in agents:
        out_tok, tools, act = agent_stats(a["events"])
        if a["status"] == "done":
            icon, col, el = "○", DIM, hms(a["mtime"] - a["start"])
        elif a["status"] == "run":
            icon, col, el = f"{G}●{R}", "", hms(now - a["start"])
        else:
            icon, col, el = f"{RED}✗{R}", DIM, hms(a["mtime"] - a["start"])
        line = f" {icon} {col}{a['label']:<14}{R} {el:>7}  {DIM}{tools}tc·{out_tok/1000:.1f}k out{R}"
        print(line)
        if a["status"] == "run":
            print(f"      {DIM}↳ {act}{R}")
    print()

    # ---------- findings history ----------
    print(f"{B}FINDINGS BY ROUND{R}  {DIM}(dev / ux / compliance · total → 0 to converge){R}")
    print(f" {DIM}rnd   dev   ux  comp   total  (C/H/M/L/N of total){R}")
    for rnum in sorted(rounds):
        d = rounds[rnum]
        dv = d.get("dev_n", "-")
        ux = d.get("ux_n", "-")
        cp = d.get("comp_n", "-")
        tot = sum(x for x in (dv, ux, cp) if isinstance(x, int))
        agg = {"CRITICAL": 0, "HIGH": 0, "MEDIUM": 0, "LOW": 0, "NITPICK": 0}
        for k in ("dev", "ux", "comp"):
            for s, n in d.get(k, {}).items():
                agg[s] = agg.get(s, 0) + n
        sev = f"{agg['CRITICAL']}/{agg['HIGH']}/{agg['MEDIUM']}/{agg['LOW']}/{agg['NITPICK']}"
        tcol = G if tot == 0 else (Y if tot <= 5 else "")
        print(f"  {rnum:<3} {str(dv):>4} {str(ux):>4} {str(cp):>4}   {tcol}{tot:>4}{R}    {DIM}{sev}{R}")
    print()

    # ---------- verify history ----------
    print(f"{B}VERIFY HISTORY{R}  {DIM}(build / lint / tests){R}")
    for i, (bo, lo, to, nf) in enumerate(verify_hist, 1):
        def m(x):
            return f"{G}✓{R}" if x else f"{RED}✗{R}"
        tail = "" if to else f" {RED}({nf} fail){R}"
        print(f"  r{i}   build {m(bo)}  lint {m(lo)}  tests {m(to)}{tail}")
    if not verify_hist:
        print(f"  {DIM}(none yet){R}")
    print()

    # ---------- verdicts ----------
    print(f"{B}LATEST REVIEWER VERDICTS{R}")
    names = {"dev": "dev ", "ux": "ux  ", "comp": "comp"}
    for k in ("dev", "ux", "comp"):
        v = verdicts[k][:150] or "(pending)"
        print(f"  {B}{names[k]}{R} {DIM}{v}{R}")
    print()

    # ---------- changed files ----------
    print(f"{B}REPO FILES CHANGED < 2 min{R}")
    found = []
    for sub in ("lib", "src", "tests", "css", "appinfo", "templates"):
        base = os.path.join(REPO, sub)
        for root, _, files in os.walk(base):
            if "node_modules" in root or "vendor" in root:
                continue
            for fn in files:
                p = os.path.join(root, fn)
                try:
                    if now - os.path.getmtime(p) < 120:
                        found.append(p.replace(REPO + "/", ""))
                except OSError:
                    pass
    for f in sorted(found)[:15]:
        print(f"  {G}~{R} {f}")
    if not found:
        print(f"  {DIM}(nothing in the last 2 min){R}")


if __name__ == "__main__":
    try:
        main()
    except Exception as e:
        print(f"{RED}dashboard error: {e}{R}")
