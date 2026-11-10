#!/bin/sh
# Opens spreadsheet files in LibreOffice Calc (a window appears briefly) and prints the frozen rows,
# column widths, first data row and autofilter ranges Calc sees.
#
# Usage: tests/office/libreoffice-inspect.sh /absolute/file.ods [/absolute/file.xlsx ...]
# SOFFICE overrides the LibreOffice binary (default: the macOS application bundle).

set -eu

SOFFICE="${SOFFICE:-/Applications/LibreOffice.app/Contents/MacOS/soffice}"
HERE="$(cd "$(dirname "$0")" && pwd)"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

mkdir -p "$WORK/profile/user/Scripts/python"
cp "$HERE/libreoffice_inspect.py" "$WORK/profile/user/Scripts/python/"

LO_INSPECT_FILES="$(printf '%s\n' "$@")"
LO_INSPECT_OUT="$WORK/report.txt"
export LO_INSPECT_FILES LO_INSPECT_OUT

# The script waits for the report, then stops LibreOffice; a dialog that blocks the macro ends
# the wait after 120 seconds.
"$SOFFICE" --norestore --nologo -env:UserInstallation="file://$WORK/profile" \
    'vnd.sun.star.script:libreoffice_inspect.py$main?language=Python&location=user' >/dev/null 2>&1 &
PID=$!
TRIES=0
while [ ! -s "$LO_INSPECT_OUT" ] && [ "$TRIES" -lt 120 ] && kill -0 "$PID" 2>/dev/null; do
    sleep 1
    TRIES=$((TRIES + 1))
done
sleep 1
kill "$PID" 2>/dev/null || true
wait "$PID" 2>/dev/null || true

if [ ! -s "$LO_INSPECT_OUT" ]; then
    echo "LibreOffice produced no report (blocked by a dialog or failed to start)." >&2
    exit 1
fi

cat "$LO_INSPECT_OUT"
