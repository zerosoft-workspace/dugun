#!/usr/bin/env bash
set -euo pipefail
root_dir="$(cd "$(dirname "$0")"/.. && pwd)"
cd "$root_dir"
if ! command -v php >/dev/null 2>&1; then
  echo "php executable not found" >&2
  exit 127
fi
find . -name '*.php' -print0 | xargs -0 -n1 php -l
