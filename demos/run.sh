#!/usr/bin/env bash
#
# Run every demo and capture its output.
#
# Iterates the demo sub-directories (demos/*/), runs each one's demo.php, and
# writes that run's combined stdout+stderr to an output.txt beside it:
#
#   demos/<name>/output.txt   — combined stdout+stderr of demos/<name>/demo.php
#
# New demo directories are picked up automatically — no edits needed here.
#
# Usage:
#   ./run.sh        # run every demo
#
# Honors $PHP to choose the interpreter (defaults to `php`). Set MAPBOX_TOKEN
# (or resources/mapbox-access-token.txt) to let the Mapbox demo render.

set -euo pipefail
shopt -s nullglob

cd "$(dirname "$0")"

PHP_BIN="${PHP:-php}"

ok=0
fail=0

set +e
for dir in */; do
    demo="${dir}demo.php"
    [ -f "$demo" ] || continue

    name="${dir%/}"
    out="${dir}output.txt"

    echo "${name}"
    "$PHP_BIN" "$demo" 2>&1 1> "$out"
    status=${PIPESTATUS[0]}

    if [ "$status" -eq 0 ]; then
        ok=$((ok + 1))
    else
        echo "  (exited with status ${status})"
        fail=$((fail + 1))
    fi
done
set -e

echo
echo "Demos run: ${ok} ok, ${fail} failed"

[ "$fail" -eq 0 ]
