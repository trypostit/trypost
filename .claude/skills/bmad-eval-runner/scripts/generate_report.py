#!/usr/bin/env python3
# /// script
# requires-python = ">=3.9"
# ///
"""Generate an aggregate HTML report for a run folder.

Reads run.json, execution-summary.json, each <eval-id>/grading.json (if present),
and triggers-result.json (if present), then renders a single-file HTML report.

Usage:
  python3 generate_report.py --run-dir PATH [-o report.html]
"""

from __future__ import annotations

import argparse
import html as html_lib
import json
import sys
from pathlib import Path


def esc(s: object) -> str:
    return html_lib.escape(str(s), quote=True)


def load(path: Path) -> dict | list | None:
    if not path.is_file():
        return None
    try:
        return json.loads(path.read_text(encoding="utf-8"))
    except json.JSONDecodeError:
        return None


def render(run_dir: Path) -> str:
    run_meta = load(run_dir / "run.json") or {}
    exec_summary = load(run_dir / "execution-summary.json") or {}
    triggers = load(run_dir / "triggers-result.json")

    eval_blocks: list[str] = []
    grading_total = 0
    grading_passed = 0

    for res in exec_summary.get("results", []):
        eval_id = str(res.get("eval_id", "?"))
        eval_dir = run_dir / eval_id
        grading = load(eval_dir / "grading.json")
        metrics = res.get("metrics") or load(eval_dir / "metrics.json") or {}
        rc = res.get("return_code")

        rows: list[str] = []
        if grading:
            for exp in grading.get("expectations", []):
                passed = bool(exp.get("passed"))
                grading_total += 1
                if passed:
                    grading_passed += 1
                rows.append(
                    f'<tr class="{ "pass" if passed else "fail" }">'
                    f'<td>{ "✔" if passed else "✘" }</td>'
                    f'<td>{esc(exp.get("text", ""))}</td>'
                    f'<td>{esc(exp.get("evidence", ""))}</td></tr>'
                )

        feedback = (grading or {}).get("eval_feedback") or {}
        feedback_html = ""
        if feedback:
            sugg = feedback.get("suggestions") or []
            sugg_html = "".join(
                f"<li><strong>{esc(s.get('assertion','(general)'))}</strong>: {esc(s.get('reason',''))}</li>"
                for s in sugg
            )
            overall = esc(feedback.get("overall", ""))
            feedback_html = (
                f'<details class="feedback"><summary>Grader feedback on the evals</summary>'
                f'<p>{overall}</p>'
                f'{"<ul>" + sugg_html + "</ul>" if sugg_html else ""}'
                f'</details>'
            )

        artifacts_listing = ""
        artifacts_dir = eval_dir / "artifacts"
        if artifacts_dir.is_dir():
            files = sorted(p for p in artifacts_dir.rglob("*") if p.is_file())
            if files:
                artifacts_listing = "<ul>" + "".join(
                    f'<li><code>{esc(p.relative_to(eval_dir))}</code> '
                    f'<span class="muted">({p.stat().st_size}b)</span></li>'
                    for p in files
                ) + "</ul>"

        tool_calls = metrics.get("tool_calls", {})
        tool_summary = ", ".join(f"{k}={v}" for k, v in sorted(tool_calls.items())) or "—"

        eval_blocks.append(f"""
        <section class="eval">
          <h3>Eval {esc(eval_id)} <span class="muted">rc={esc(rc)} · {esc(metrics.get('elapsed_s', '?'))}s</span></h3>
          <p class="muted">Tool calls: {esc(tool_summary)} · output {esc(metrics.get('output_chars', 0))}b · transcript {esc(metrics.get('transcript_chars', 0))}b</p>
          { '<table><thead><tr><th></th><th>Expectation</th><th>Evidence</th></tr></thead><tbody>' + ''.join(rows) + '</tbody></table>' if rows else '<p class="muted">No grading.json yet.</p>' }
          {feedback_html}
          <details><summary>Artifacts</summary>{artifacts_listing or '<p class="muted">No artifacts captured.</p>'}</details>
        </section>
        """)

    triggers_html = ""
    if triggers:
        rows = []
        for r in triggers.get("results", []):
            rows.append(
                f'<tr class="{ "pass" if r["pass"] else "fail" }">'
                f'<td>{ "✔" if r["pass"] else "✘" }</td>'
                f'<td>{esc(r["query"])}</td>'
                f'<td>{esc(r["should_trigger"])}</td>'
                f'<td>{r["triggers"]}/{r["runs"]} ({r["trigger_rate"]:.2f})</td></tr>'
            )
        s = triggers.get("summary", {})
        triggers_html = f"""
        <section class="triggers">
          <h2>Trigger Evals — {s.get('passed',0)}/{s.get('total',0)} pass</h2>
          <table><thead><tr><th></th><th>Query</th><th>Should fire</th><th>Rate</th></tr></thead>
          <tbody>{''.join(rows)}</tbody></table>
        </section>
        """

    artifact_summary = ""
    if exec_summary:
        artifact_summary = (
            f"<p>Executed {exec_summary.get('executed', 0)} / {exec_summary.get('total', 0)} "
            f"evals · {exec_summary.get('exec_failures', 0)} execution failures · "
            f"grader: {grading_passed}/{grading_total} expectations passed</p>"
        )

    return f"""<!doctype html>
<html><head><meta charset="utf-8"><title>Eval Run — {esc(run_meta.get('skill_name','?'))}</title>
<style>
  body {{ font: 14px/1.5 system-ui, sans-serif; max-width: 1080px; margin: 2em auto; color: #222; padding: 0 1em; }}
  h1, h2, h3 {{ font-weight: 600; }}
  h1 {{ font-size: 1.6em; margin-bottom: 0.2em; }}
  .meta {{ color: #666; margin-bottom: 1.5em; }}
  .muted {{ color: #888; font-weight: normal; }}
  section.eval {{ border: 1px solid #ddd; border-radius: 6px; padding: 1em 1.2em; margin: 1em 0; background: #fafafa; }}
  table {{ width: 100%; border-collapse: collapse; margin: 0.5em 0; font-size: 13px; }}
  th, td {{ text-align: left; padding: 6px 8px; border-bottom: 1px solid #eee; vertical-align: top; }}
  tr.pass td:first-child {{ color: #2c8a3a; font-weight: 700; }}
  tr.fail td:first-child {{ color: #b3261e; font-weight: 700; }}
  tr.fail {{ background: #fdf3f2; }}
  details.feedback {{ margin-top: 0.6em; padding: 0.4em 0.7em; background: #fff8e1; border-radius: 4px; }}
  details summary {{ cursor: pointer; font-weight: 600; }}
  code {{ background: #eee; padding: 1px 4px; border-radius: 3px; }}
</style></head>
<body>
<h1>{esc(run_meta.get('skill_name','?'))} — eval run</h1>
<div class="meta">
  Run id: <code>{esc(run_meta.get('run_id','?'))}</code> ·
  isolation: {esc(run_meta.get('isolation','?'))} ·
  started: {esc(run_meta.get('started_at','?'))}
</div>
{artifact_summary}
{''.join(eval_blocks)}
{triggers_html}
</body></html>
"""


def main() -> int:
    parser = argparse.ArgumentParser(description="Generate HTML report for an eval run folder")
    parser.add_argument("--run-dir", required=True, type=Path)
    parser.add_argument("-o", "--output", type=Path, default=None)
    args = parser.parse_args()

    run_dir = args.run_dir.resolve()
    if not run_dir.is_dir():
        print(f"run-dir not found: {run_dir}", file=sys.stderr)
        return 2

    out = args.output or (run_dir / "report.html")
    out.write_text(render(run_dir), encoding="utf-8")
    print(str(out))
    return 0


if __name__ == "__main__":
    sys.exit(main())
