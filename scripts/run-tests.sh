#!/usr/bin/env bash
# 1. Relative path: scripts/run-tests.sh
# 2. Description: THE TEST RUNNER — one command for every battery in the repo.
#                 Runs the byte-identity gate first (a served byte that moved
#                 stops the ride), then the batteries, and answers one verdict.
# 3. Expects: PHP 8.4+ on PATH, the repo root as cwd.
# 4. Provides: `npm test` — exit 0 when everything is green, 1 otherwise.
# 5. SPDX-License-Identifier: MPL-2.0
# 6. Copyright (c) 2026 Homahuki GmbH
#
# Usage: scripts/run-tests.sh [--write-baseline]
#   --write-baseline  recapture the byte-identity baseline (a deliberate act:
#                     it says "the served bytes moved ON PURPOSE")

set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT" || exit 2

WRITE=""
if [ "${1:-}" = "--write-baseline" ]; then
    WRITE="--write"
fi

FAILED=0
run() {
    local label="$1"
    shift
    echo ""
    echo "══════════════════════════════════════════════════════════════"
    echo "  ${label}"
    echo "══════════════════════════════════════════════════════════════"
    if "$@"; then
        echo "  → ${label}: GREEN"
    else
        echo "  → ${label}: RED"
        FAILED=1
    fi
}

# ——— 0. the byte-identity gate ———
# The served bytes must not move. A refactor step that changes them stops here.
run "byte-identity (served pages)" php tests/snapshot.php $WRITE

# ——— 0.5 the architecture law ———
run "arch-lint (the four rules)" npm run lint:arch --silent

# ——— 1. the batteries ———
run "wares battery (install/enable/remove gates)" php tests/wares-battery.php
run "boundary battery (plugin × core wall)" php tests/boundary-battery.php
run "lid battery (the code lid)" php tests/lid-battery.php
run "bienenstich battery (the API ware's wall)" php tests/battery-bienenstich.php
run "refusal battery (every domain refusal, pinned)" php tests/refusal-battery.php

# ——— the verdict ———
echo ""
echo "══════════════════════════════════════════════════════════════"
if [ "$FAILED" -eq 0 ]; then
    echo "  ALL GREEN"
else
    echo "  SOMETHING IS RED"
fi
echo "══════════════════════════════════════════════════════════════"
exit "$FAILED"
