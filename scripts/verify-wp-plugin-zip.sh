#!/usr/bin/env bash
# Strict WordPress plugin ZIP layout verification (CI + local).
set -euo pipefail

ZIP="${1:-}"
if [[ -z "$ZIP" || ! -f "$ZIP" ]]; then
  echo "Usage: $0 <path-to-paxdesign-toolbar-x.y.z.zip>" >&2
  exit 1
fi

if ! command -v unzip >/dev/null 2>&1; then
  echo "unzip is required" >&2
  exit 1
fi

mapfile -t ENTRIES < <(unzip -Z1 "$ZIP" | sed 's|\\|/|g' | sed '/^$/d')

if [[ ${#ENTRIES[@]} -eq 0 ]]; then
  echo "FAIL: ZIP is empty" >&2
  exit 1
fi

declare -A ROOTS=()
for entry in "${ENTRIES[@]}"; do
  root="${entry%%/*}"
  [[ -n "$root" ]] && ROOTS["$root"]=1
done

if [[ ${#ROOTS[@]} -ne 1 || -z "${ROOTS[paxdesign-toolbar]+x}" ]]; then
  echo "FAIL: ZIP must have exactly one root folder 'paxdesign-toolbar/' (found: ${!ROOTS[*]})" >&2
  exit 1
fi

MAIN='paxdesign-toolbar/paxdesign-toolbar.php'
FOUND_MAIN=0
for entry in "${ENTRIES[@]}"; do
  if [[ "$entry" == "$MAIN" ]]; then
    FOUND_MAIN=1
    break
  fi
done
if [[ $FOUND_MAIN -ne 1 ]]; then
  echo "FAIL: missing required main file: $MAIN" >&2
  exit 1
fi

for entry in "${ENTRIES[@]}"; do
  if [[ "$entry" == paxdesign-toolbar/paxdesign-toolbar/* ]]; then
    echo "FAIL: double-nested ZIP path: $entry" >&2
    exit 1
  fi
done

for entry in "${ENTRIES[@]}"; do
  if [[ "$entry" =~ ^paxdesign-toolbar-[0-9] ]]; then
    echo "FAIL: versioned root path in ZIP: $entry" >&2
    exit 1
  fi
done

for entry in "${ENTRIES[@]}"; do
  if [[ "$entry" == "paxdesign-toolbar.php" ]]; then
    echo "FAIL: flat ZIP (main file at archive root)" >&2
    exit 1
  fi
done

has_prefix() {
  local prefix="$1"
  for entry in "${ENTRIES[@]}"; do
    if [[ "$entry" == "$prefix" || "$entry" == "$prefix"* ]]; then
      return 0
    fi
  done
  return 1
}

for dir in includes assets templates; do
  if ! has_prefix "paxdesign-toolbar/${dir}/"; then
    echo "FAIL: missing required directory: paxdesign-toolbar/${dir}/" >&2
    exit 1
  fi
done

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
unzip -q "$ZIP" -d "$TMP"
EXTRACTED_MAIN="$TMP/paxdesign-toolbar/paxdesign-toolbar.php"
if [[ ! -f "$EXTRACTED_MAIN" ]]; then
  echo "FAIL: extract test — $EXTRACTED_MAIN not found" >&2
  exit 1
fi

if ! head -n 20 "$EXTRACTED_MAIN" | grep -q 'Plugin Name:'; then
  echo "FAIL: main file missing Plugin Name header" >&2
  exit 1
fi

echo "WP-ZIP-OK: $ZIP (WordPress-uploadable layout verified)"
