#!/usr/bin/env bash
set -euo pipefail

if [[ $# -ne 1 ]]; then
  echo "Usage: $0 OUTPUT.zip" >&2
  exit 2
fi

project_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
archive="$(realpath -m "$1")"
staging="$(mktemp -d)"
trap 'rm -rf "$staging"' EXIT

if [[ ! -f "$project_root/vendor/autoload.php" ]]; then
  echo 'Install production Composer dependencies before building.' >&2
  exit 1
fi

mkdir -p "$staging/faluss-platform" "$(dirname "$archive")"
rsync -a \
  --exclude='.*' \
  --exclude='tests' \
  "$project_root/faluss-platform.php" \
  "$project_root/readme.txt" \
  "$project_root/assets" \
  "$project_root/contracts" \
  "$project_root/licenses" \
  "$project_root/src" \
  "$project_root/vendor" \
  "$staging/faluss-platform/"

if [[ -n "$(find "$staging/faluss-platform" -type l -print -quit)" ]]; then
  echo 'Release inputs must not contain symlinks.' >&2
  exit 1
fi

find "$staging/faluss-platform" -type d -exec chmod 755 {} +
find "$staging/faluss-platform" -type f -exec chmod 644 {} +

rm -f "$archive"
python3 - "$staging" "$archive" <<'PY'
import sys
from pathlib import Path
from zipfile import ZIP_DEFLATED, ZipFile, ZipInfo

root = Path(sys.argv[1])
with ZipFile(sys.argv[2], "w", ZIP_DEFLATED, compresslevel=9) as archive:
    for path in sorted((root / "faluss-platform").rglob("*")):
        name = path.relative_to(root).as_posix()
        info = ZipInfo(name + ("/" if path.is_dir() else ""))
        info.create_system = 3
        info.external_attr = (0o40755 if path.is_dir() else 0o100644) << 16
        if path.is_dir():
            archive.writestr(info, b"")
        else:
            archive.writestr(info, path.read_bytes(), compress_type=ZIP_DEFLATED, compresslevel=9)
PY
python3 "$project_root/scripts/validate-release.py" "$archive"
