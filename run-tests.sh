#!/usr/bin/env bash
#
# Run the PHPUnit test suite and write reports to build/.
#
# Usage:
#   ./run-tests.sh                  # runs the full suite
#   ./run-tests.sh --testsuite unit # extra args pass through to phpunit

set -euo pipefail

cd "$(dirname "$0")"

BUILD_DIR="build"

rm -rf "$BUILD_DIR/integration-tests"
rm -f "$BUILD_DIR"/test-*

mkdir -p "$BUILD_DIR"

PHPUNIT="vendor/bin/phpunit"
if [[ ! -f "$PHPUNIT" ]]; then
    echo "PHPUnit not installed. Run: composer install" >&2
    exit 1
fi

PHP_BIN="${PHP:-php}"

OUTPUT_LOG="$BUILD_DIR/test-output.txt"
: > "$OUTPUT_LOG"

set +e
"$PHP_BIN" -d error_log=/dev/stderr "$PHPUNIT" \
    --testdox-text "$BUILD_DIR/test-report.txt" \
    --log-junit "$BUILD_DIR/test-report-junit.xml" \
    --display-warnings \
    --display-deprecations \
    --display-notices \
    "$@" 2>&1 | tee "$OUTPUT_LOG"
status=${PIPESTATUS[0]}
set -e

echo
echo "=========================================="
echo "Reports written to: $BUILD_DIR/"
echo "=========================================="
ls -1 "$BUILD_DIR"/ 2>/dev/null | sort | sed 's/^/  /'

exit $status
