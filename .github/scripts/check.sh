#!/usr/bin/env bash
# Syntax-check every PHP file and run the framework-free test suite.
# Run from the repository root: .github/scripts/check.sh
set -euo pipefail

failed=0
count=0
while IFS= read -r -d '' file; do
  count=$((count + 1))
  if ! output="$(php -l "$file" 2>&1)"; then
    echo "::error file=${file}::PHP syntax error"
    echo "$output"
    failed=1
  fi
done < <(find . -name '*.php' -not -path './.git/*' -not -path './vendor/*' -print0)

echo "Linted $count PHP files"
if [ "$count" -eq 0 ]; then
  echo "::error::No PHP files found, refusing to continue"
  exit 1
fi
if [ "$failed" -ne 0 ]; then
  exit 1
fi

php tests/run.php
