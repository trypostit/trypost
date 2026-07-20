#!/usr/bin/env python3
# /// script
# requires-python = ">=3.9"
# ///
"""Run claude interactively via PTY so the Skill tool is available.

In `claude -p` (print mode) the Skill tool is never offered — Claude handles
everything inline. Running `claude` in interactive mode activates the Skill
tool so dependency skills installed in .claude/skills/ can be properly invoked.

The PTY tricks claude into thinking it has a terminal (interactive mode) while
we capture its stream-json output programmatically.

Usage:
  python3 pty_runner.py --prompt-file /path/to/prompt.txt \\
                        --output /path/to/transcript.jsonl \\
                        [--timeout 600]
  python3 pty_runner.py --prompt "Run headless. ..." --output transcript.jsonl
"""

from __future__ import annotations

import argparse
import json
import os
import pty
import re
import select
import subprocess
import sys
import time
from pathlib import Path

ANSI_RE = re.compile(r"\x1b(?:[@-Z\\-_]|\[[0-?]*[ -/]*[@-~])|\r")

# How long to wait for claude to initialize before sending the prompt.
# Claude loads skill registry, checks credentials, etc. on startup.
INIT_WAIT_S = 5.0

# How long to wait after the stream-json 'result' event before killing claude.
# Trailing tool-result output sometimes follows the result event.
POST_RESULT_S = 4.0


def _strip_ansi(text: str) -> str:
    return ANSI_RE.sub("", text)


def run_interactive(prompt: str, output: Path, timeout: int = 600) -> None:
    """Spawn claude interactively via PTY, send one prompt, capture transcript."""
    master, slave = pty.openpty()

    proc = subprocess.Popen(
        [
            "claude",
            "--output-format", "stream-json",
            "--verbose",
            "--dangerously-skip-permissions",
        ],
        stdin=slave,
        stdout=slave,
        stderr=slave,
        close_fds=True,
    )
    os.close(slave)

    json_lines: list[str] = []
    buf = b""
    prompt_sent = False
    done_at: float | None = None
    start = time.time()

    try:
        while True:
            elapsed = time.time() - start
            if elapsed > timeout:
                print(f"[pty_runner] timeout after {elapsed:.0f}s", file=sys.stderr)
                break
            if done_at is not None and (time.time() - done_at) > POST_RESULT_S:
                break

            # Short select so we stay responsive but don't spin.
            r, _, _ = select.select([master], [], [], 0.3)

            if r:
                try:
                    chunk = os.read(master, 8192)
                except OSError:
                    break  # PTY closed — claude exited
                buf += chunk

                # Process all complete lines in buffer.
                while b"\n" in buf:
                    raw, buf = buf.split(b"\n", 1)
                    line = _strip_ansi(raw.decode("utf-8", errors="replace")).strip()
                    if not line.startswith("{"):
                        continue
                    json_lines.append(line)
                    try:
                        obj = json.loads(line)
                        # 'result' marks end of a claude turn.
                        if obj.get("type") == "result" and done_at is None:
                            done_at = time.time()
                            print(
                                f"[pty_runner] result event at t={time.time()-start:.1f}s "
                                f"({len(json_lines)} lines so far)",
                                file=sys.stderr,
                            )
                    except json.JSONDecodeError:
                        pass
            else:
                # Silence window — send prompt once claude has had time to init.
                if not prompt_sent and (time.time() - start) >= INIT_WAIT_S:
                    os.write(master, (prompt + "\n").encode())
                    prompt_sent = True
                    print(
                        f"[pty_runner] prompt sent at t={time.time()-start:.1f}s",
                        file=sys.stderr,
                    )

    finally:
        # Politely ask claude to exit, then hard-kill if needed.
        try:
            os.write(master, b"exit\n")
            time.sleep(0.3)
        except OSError:
            pass
        try:
            proc.terminate()
            proc.wait(timeout=5)
        except Exception:
            try:
                proc.kill()
            except Exception:
                pass
        try:
            os.close(master)
        except OSError:
            pass

    output.parent.mkdir(parents=True, exist_ok=True)
    content = "\n".join(json_lines) + ("\n" if json_lines else "")
    output.write_text(content, encoding="utf-8")
    print(
        f"[pty_runner] wrote {len(json_lines)} transcript lines → {output}",
        file=sys.stderr,
    )


def main() -> int:
    p = argparse.ArgumentParser(
        description="Run claude interactively via PTY and capture stream-json transcript"
    )
    grp = p.add_mutually_exclusive_group(required=True)
    grp.add_argument("--prompt", help="Prompt text")
    grp.add_argument("--prompt-file", type=Path, help="File containing the prompt")
    p.add_argument("--output", type=Path, required=True, help="Output .jsonl transcript file")
    p.add_argument("--timeout", type=int, default=600, help="Hard timeout in seconds")
    args = p.parse_args()

    prompt = (
        args.prompt_file.read_text(encoding="utf-8").strip()
        if args.prompt_file
        else args.prompt
    )
    run_interactive(prompt, args.output, args.timeout)
    return 0


if __name__ == "__main__":
    sys.exit(main())
