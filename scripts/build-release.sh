#!/usr/bin/env bash
# Build WordPress-installable ZIP — canonical path used by GitHub Actions.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

MAIN="$ROOT/paxdesign-toolbar/paxdesign-toolbar.php"
if [[ ! -f "$MAIN" ]]; then
  echo "Plugin main file not found: $MAIN" >&2
  exit 1
fi

VER="$(grep -E "define\s*\(\s*'PDX_VERSION'" "$MAIN" | sed -E "s/.*'([^']+)'.*/\1/")"
if [[ -z "$VER" ]]; then
  echo "Could not read PDX_VERSION from $MAIN" >&2
  exit 1
fi

echo "Linting PHP..."
while IFS= read -r -d '' file; do
  php -l "$file" >/dev/null
done < <(find paxdesign-toolbar -name '*.php' -print0)

STAGING="$(mktemp -d)"
trap 'rm -rf "$STAGING"' EXIT

rsync -a \
  --exclude='.git' \
  --exclude='.github' \
  --exclude='releases' \
  --exclude='scripts' \
  --exclude='node_modules' \
  --exclude='.cursor' \
  paxdesign-toolbar/ "$STAGING/paxdesign-toolbar/"

if [[ -f "$STAGING/paxdesign-toolbar/paxdesign-toolbar/paxdesign-toolbar.php" ]]; then
  echo "FAIL: staging is double-nested" >&2
  exit 1
fi
if [[ ! -f "$STAGING/paxdesign-toolbar/paxdesign-toolbar.php" ]]; then
  echo "FAIL: staging missing paxdesign-toolbar/paxdesign-toolbar.php" >&2
  exit 1
fi

mkdir -p releases
ZIP="$ROOT/releases/paxdesign-toolbar-${VER}.zip"
rm -f "$ZIP"

# One root folder in archive: paxdesign-toolbar/
( cd "$STAGING" && zip -rq "$ZIP" paxdesign-toolbar )

echo "Built: $ZIP"
echo "Version: $VER"
sha256sum "$ZIP" | tee "$ROOT/releases/paxdesign-toolbar-${VER}.zip.sha256"

bash "$ROOT/scripts/verify-wp-plugin-zip.sh" "$ZIP"

if [[ -x "${PHP_BIN:-}" ]] || command -v php >/dev/null 2>&1; then
  PHP="${PHP_BIN:-php}"
  if [[ -f "$ROOT/scripts/wp-bootstrap-smoke.php" ]]; then
    "$PHP" "$ROOT/scripts/wp-bootstrap-smoke.php"
  fi
  if [[ -f "$ROOT/scripts/simulate-wp-plugin-detect.php" ]]; then
    "$PHP" "$ROOT/scripts/simulate-wp-plugin-detect.php" "$ZIP"
  fi
fi

echo "Release build complete."
