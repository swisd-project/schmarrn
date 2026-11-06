#!/usr/bin/env bash
# 1. Relative path: scripts/dev-serve.sh
# 2. Description: The dev server under the PORT LAW (2026-11-07, user-mandated
#                 after a live session was killed under them): a running
#                 session is SACRED. This script never kills anything — it
#                 asks the port, and if a session sits there, it AUTO-STEPS
#                 to the next free port instead. The chosen port is printed.
#                 Usage: scripts/dev-serve.sh [start-port]   (default 8080)
# 3. Expects: php on PATH; bash (the /dev/tcp probe is a bash builtin).
# 4. Provides: php -S on the first free port at or above the start port.
# 5. SPDX-License-Identifier: MPL-2.0
# 6. Copyright (c) 2026 Homahuki GmbH

set -euo pipefail

PORT="${1:-8080}"
cd "$(cd "$(dirname "$0")/.." && pwd)"

# The probe opens a TCP connection — it KNOCKS, it never kicks.
port_busy() {
  (exec 3<>"/dev/tcp/127.0.0.1/$1") 2>/dev/null && return 0
  return 1
}

while port_busy "$PORT"; do
  echo "— port $PORT is busy: a live session sits there. It does NOT get touched. —"
  PORT=$((PORT + 1))
done

echo "— serving on http://localhost:$PORT —"
exec php -S "localhost:$PORT" index.php
