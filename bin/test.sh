#!/usr/bin/env bash
# Every test: the chat window in Node (jsdom), and the plugin inside a real
# WordPress (WordPress Playground: PHP compiled to WebAssembly, no Docker, no
# local PHP) on the newest PHP and on the oldest one the plugin supports.
#
#   bin/test.sh          # all
#   bin/test.sh js       # only the chat window
#   bin/test.sh php      # only the plugin in WordPress
#
# PHP_VERSIONS="8.4 8.3 7.4" bin/test.sh php   to try other versions.
set -uo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PLAYGROUND="${PLAYGROUND:-@wp-playground/cli@3}"
PHP_VERSIONS="${PHP_VERSIONS:-8.3 7.4}"
what="${1:-all}"
failed=0

if [ "$what" = all ] || [ "$what" = js ]; then
  echo "== The chat window (Node $(node -v))"
  if [ ! -d "$ROOT/node_modules/jsdom" ]; then
    (cd "$ROOT" && npm install --no-audit --no-fund --silent) || failed=1
  fi
  (cd "$ROOT" && npm test --silent) || failed=1
fi

if [ "$what" = all ] || [ "$what" = php ]; then
  python3 "$ROOT/bin/i18n.py" check || failed=1
  for php in $PHP_VERSIONS; do
    echo
    echo "== The plugin in WordPress, PHP $php"
    out=$(npx -y "$PLAYGROUND" php --php "$php" \
      --mount="$ROOT:/wordpress/wp-content/plugins/iamagnus-chat" \
      -- /wordpress/wp-content/plugins/iamagnus-chat/tests/php/run-tests.php 2>&1 \
      | grep -v -E 'EBADENGINE|package:|required:|current:|^npm warn')
    echo "$out"
    echo "$out" | grep -q -E '^RESULT [0-9]+ passed, 0 failed$' || failed=1
  done
fi

if [ "$what" = live ]; then
  # Not part of "all": it calls a real Magnus. With MAGNUS_API_KEY set it
  # runs one real turn (tokens spent, a conversation recorded).
  echo "== Against a real Magnus"
  defines=()
  [ -n "${MAGNUS_API_KEY:-}" ] && defines+=(--define MAGNUS_API_KEY "$MAGNUS_API_KEY")
  [ -n "${MAGNUS_BASE_URL:-}" ] && defines+=(--define MAGNUS_BASE_URL "$MAGNUS_BASE_URL")
  out=$(npx -y "$PLAYGROUND" php ${defines[@]+"${defines[@]}"} \
    --mount="$ROOT:/wordpress/wp-content/plugins/iamagnus-chat" \
    -- /wordpress/wp-content/plugins/iamagnus-chat/tests/php/live-check.php 2>&1 \
    | grep -v -E 'EBADENGINE|package:|required:|current:|^npm warn')
  echo "$out"
  echo "$out" | grep -q '^RESULT everything passed$' || failed=1
fi

echo
if [ "$failed" = 0 ]; then echo "everything passed"; else echo "SOMETHING FAILED"; fi
exit "$failed"
