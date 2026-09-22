#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
find "$ROOT" -type f -name '*.php' -print0 | xargs -0 -n1 php -l >/dev/null
if grep -RInE 'wp_add_inline_script|wp_add_inline_style|<script|<style|style="' "$ROOT" --include='*.php' | grep -v "return str_replace( '<script '"; then
  echo "CSP smoke check failed: inline script/style marker found." >&2
  exit 1
fi
if grep -RInE '\.style\.' "$ROOT/assets" --include='*.js'; then
  echo "CSP smoke check failed: JS inline style mutation found." >&2
  exit 1
fi
if command -v node >/dev/null 2>&1; then
  find "$ROOT/assets" -type f -name '*.js' -print0 | xargs -0 -n1 node --check
  node "$ROOT/tests/frontend.test.cjs"
fi
echo "WhatsApp Tracker smoke checks passed."
