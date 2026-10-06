#!/usr/bin/env bash
# Builds the zip a site owner uploads in Plugins → Add New → Upload, with only
# what the plugin needs to run, and runs the PHP tests against that copy so a
# file missing from the zip fails here, not on someone's site.
#
#   bin/build-zip.sh          # dist/iamagnus-chat-<version>.zip
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SLUG="iamagnus-chat"
VERSION=$(sed -n 's/^ \* Version:[[:space:]]*//p' "$ROOT/$SLUG.php" | head -1)
STABLE=$(sed -n 's/^Stable tag:[[:space:]]*//p' "$ROOT/readme.txt" | head -1)
CONSTANT=$(sed -n "s/^define( 'IAMAGNUS_CHAT_VERSION', '\(.*\)' );/\1/p" "$ROOT/$SLUG.php")

if [ "$VERSION" != "$STABLE" ] || [ "$VERSION" != "$CONSTANT" ]; then
  echo "version mismatch: header $VERSION, readme.txt Stable tag $STABLE, IAMAGNUS_CHAT_VERSION $CONSTANT" >&2
  exit 1
fi

python3 "$ROOT/bin/i18n.py" mo

STAGE="$ROOT/dist/$SLUG"
rm -rf "$ROOT/dist"
mkdir -p "$STAGE"
cp "$ROOT/$SLUG.php" "$ROOT/uninstall.php" "$ROOT/readme.txt" "$ROOT/LICENSE" "$STAGE/"
cp -R "$ROOT/includes" "$ROOT/assets" "$ROOT/languages" "$STAGE/"
(cd "$ROOT/dist" && zip -qr "$SLUG-$VERSION.zip" "$SLUG")

echo "== The tests, against the copy that goes in the zip"
out=$(npx -y "${PLAYGROUND:-@wp-playground/cli@3}" php \
  --mount="$STAGE:/wordpress/wp-content/plugins/$SLUG" \
  --mount="$ROOT/tests/php:/tests" \
  -- /tests/run-tests.php 2>&1 | grep -v -E 'EBADENGINE|package:|required:|current:|^npm warn')
echo "$out" | grep -E '^RESULT|FAIL'
echo "$out" | grep -q -E '^RESULT [0-9]+ passed, 0 failed$' || { echo "the packaged plugin failed its tests" >&2; exit 1; }

echo "dist/$SLUG-$VERSION.zip ($(du -h "$ROOT/dist/$SLUG-$VERSION.zip" | cut -f1)), $(cd "$ROOT/dist" && unzip -l "$SLUG-$VERSION.zip" | tail -1 | awk '{print $2}') files"
