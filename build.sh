#!/usr/bin/env bash
# Rebuild assets/css/app.css from assets/css/input.css using the Tailwind CLI.
#
# Node + npm are only needed for the build. Packages are installed outside the
# web root (in a temp dir) so node_modules never ends up publicly reachable.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")" && pwd)"
WORK="${QUIZ_TAILWIND_DIR:-/tmp/quiz-tailwind}"

mkdir -p "$WORK"
cd "$WORK"

if [ ! -x node_modules/.bin/tailwindcss ]; then
  echo "Installing tailwindcss in $WORK ..."
  npm init -y >/dev/null 2>&1 || true
  npm i -D tailwindcss@3
fi

# Run from the project root so the `content` globs in tailwind.config.js resolve.
cd "$ROOT"
"$WORK/node_modules/.bin/tailwindcss" \
  -c "$ROOT/tailwind.config.js" \
  -i assets/css/input.css \
  -o assets/css/app.css \
  --minify

echo "Wrote $ROOT/assets/css/app.css"
