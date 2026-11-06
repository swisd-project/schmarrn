#!/usr/bin/env bash
# 1. Relative path: scripts/ship-release.sh
# 2. Description: Pack the RELEASE ASSET — the one zip that carries the whole
#                 ship, the tag's source tree PLUS the built dashboard
#                 (build/). GitHub's auto zipball is source alone (build/ is
#                 git-ignored), so the core self-update can only move the
#                 dashboard when the release wears this zip as an asset.
#                 Usage:  npm run build && scripts/ship-release.sh v1.2.0
#                 Then attach schmarrn-<version>.zip to that release on GitHub.
# 3. Expects: git (tag exists), node/npm for the build, zip on PATH.
# 4. Provides: schmarrn-<version>.zip in the repo root.
# 5. SPDX-License-Identifier: MPL-2.0
# 6. Copyright (c) 2026 Homahuki GmbH

set -euo pipefail

TAG="${1:?tag name, e.g. v1.2.0}"
VERSION="${TAG#v}"
cd "$(cd "$(dirname "$0")/.." && pwd)"

git rev-parse -q --verify "refs/tags/$TAG" >/dev/null || { echo "no tag $TAG here — cut it first" >&2; exit 1; }

echo "— building the dashboard —"
npm run build

STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

echo "— unpacking the $TAG source tree —"
git archive --prefix="schmarrn-$VERSION/" "$TAG" | tar -x -C "$STAGE"
cp -r build "$STAGE/schmarrn-$VERSION/build"

OUT="schmarrn-$VERSION.zip"
rm -f "$OUT"
( cd "$STAGE" && zip -qr "$OLDPWD/$OUT" "schmarrn-$VERSION" )

echo "— packed: $OUT ($(du -h "$OUT" | cut -f1)) —"
echo "attach it to the $TAG release on GitHub as an asset; from then on,"
echo "the core self-update rides it and the dashboard moves with the core."
