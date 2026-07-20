#!/usr/bin/env python3
# /// script
# requires-python = ">=3.9"
# ///
"""Detect Docker and build the bmad-eval-runner image when needed.

Usage:
  python3 docker_setup.py --check                # exit 0 if image is ready, 1 otherwise
  python3 docker_setup.py --build                # build the image (no-op if present)
  python3 docker_setup.py --rebuild              # force rebuild
"""

from __future__ import annotations

import argparse
import json
import shutil
import subprocess
import sys
from pathlib import Path


IMAGE_TAG = "bmad-eval-runner:latest"
SCRIPT_DIR = Path(__file__).resolve().parent
DOCKERFILE = SCRIPT_DIR.parent / "assets" / "Dockerfile"


def docker_available() -> tuple[bool, str]:
    if shutil.which("docker") is None:
        return False, "docker CLI not found on PATH"
    try:
        result = subprocess.run(
            ["docker", "info"],
            capture_output=True,
            text=True,
            timeout=5,
        )
        if result.returncode != 0:
            return False, f"`docker info` failed: {result.stderr.strip().splitlines()[-1] if result.stderr.strip() else 'unknown'}"
        return True, "ok"
    except subprocess.TimeoutExpired:
        return False, "`docker info` timed out"
    except Exception as e:
        return False, f"docker check error: {e}"


def image_present(tag: str = IMAGE_TAG) -> bool:
    try:
        result = subprocess.run(
            ["docker", "image", "inspect", tag],
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
            timeout=10,
        )
        return result.returncode == 0
    except Exception:
        return False


def build_image(tag: str = IMAGE_TAG, force: bool = False, verbose: bool = True) -> int:
    if not DOCKERFILE.is_file():
        print(f"Dockerfile missing at {DOCKERFILE}", file=sys.stderr)
        return 2

    cmd = ["docker", "build", "-t", tag, "-f", str(DOCKERFILE), str(DOCKERFILE.parent)]
    if force:
        cmd.insert(2, "--no-cache")

    if verbose:
        print(f"Building {tag} from {DOCKERFILE} ...", file=sys.stderr)

    proc = subprocess.run(cmd, stdout=sys.stderr if verbose else subprocess.DEVNULL, stderr=sys.stderr)
    return proc.returncode


def main() -> int:
    parser = argparse.ArgumentParser(description="Manage the bmad-eval-runner Docker image")
    group = parser.add_mutually_exclusive_group(required=True)
    group.add_argument("--check", action="store_true", help="Report status as JSON; exit 0 if image is ready")
    group.add_argument("--build", action="store_true", help="Build the image (no-op if already present)")
    group.add_argument("--rebuild", action="store_true", help="Force rebuild")
    parser.add_argument("--quiet", action="store_true")
    args = parser.parse_args()

    available, reason = docker_available()
    present = image_present() if available else False

    if args.check:
        print(json.dumps({
            "docker_available": available,
            "docker_reason": reason,
            "image_present": present,
            "image_tag": IMAGE_TAG,
        }, indent=2))
        return 0 if (available and present) else 1

    if not available:
        print(f"Docker is not available: {reason}", file=sys.stderr)
        return 3

    if args.rebuild:
        return build_image(force=True, verbose=not args.quiet)

    if args.build:
        if present:
            if not args.quiet:
                print(f"{IMAGE_TAG} already present; skipping build (use --rebuild to force).", file=sys.stderr)
            return 0
        return build_image(force=False, verbose=not args.quiet)

    return 0


if __name__ == "__main__":
    sys.exit(main())
