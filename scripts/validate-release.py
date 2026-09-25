#!/usr/bin/env python3
"""Reject plugin archives that WordPress or the update server cannot consume."""

import argparse
import re
import sys
from pathlib import Path
from zipfile import BadZipFile, ZipFile

ROOT = "faluss-platform/"
ALLOWED_ROOT_ENTRIES = {
    "faluss-platform.php", "readme.txt", "assets", "contracts", "licenses", "src", "vendor"
}
ALLOWED_VENDOR_ENTRIES = {"autoload.php", "composer", "stripe", "yahnis-elsts"}
REQUIRED = {
    ROOT + "faluss-platform.php",
    ROOT + "readme.txt",
    ROOT + "vendor/autoload.php",
    ROOT + "vendor/yahnis-elsts/plugin-update-checker/load-v5p7.php",
    ROOT + "vendor/stripe/stripe-php/init.php",
}


def header_value(content: str, name: str) -> str:
    match = re.search(r"^\s*\*?\s*" + re.escape(name) + r":\s*([^\r\n]+)", content, re.M)
    return match.group(1).strip() if match else ""


def validate(path: Path) -> list[str]:
    errors = []
    try:
        with ZipFile(path) as archive:
            names = archive.namelist()
            if archive.testzip() is not None:
                errors.append("The archive contains an unreadable entry")
            if len(names) != len(set(names)):
                errors.append("The archive contains duplicate entries")
            for info in archive.infolist():
                name = info.filename
                parts = name.split("/")
                if not name.startswith(ROOT) or ".." in parts or "\\" in name:
                    errors.append(f"Invalid archive path: {name}")
                    continue
                if len(parts) > 1 and parts[1] and parts[1] not in ALLOWED_ROOT_ENTRIES:
                    errors.append(f"Development or unexpected file: {name}")
                if any(part.startswith(".") for part in parts):
                    errors.append(f"Hidden file is not allowed: {name}")
                if len(parts) > 2 and parts[1] == "vendor" and parts[2] and parts[2] not in ALLOWED_VENDOR_ENTRIES:
                    errors.append(f"Development dependency is not allowed: {name}")
                mode = info.external_attr >> 16
                if mode & 0o170000 == 0o120000:
                    errors.append(f"Symlink is not allowed: {name}")
                expected = 0o755 if info.is_dir() else 0o644
                if mode & 0o777 != expected:
                    errors.append(f"Incorrect permissions on {name}: {oct(mode & 0o777)}")
            for name in sorted(REQUIRED - set(names)):
                errors.append(f"Missing required file: {name}")
            if ROOT + "faluss-platform.php" in names and ROOT + "readme.txt" in names:
                plugin = archive.read(ROOT + "faluss-platform.php").decode("utf-8")
                readme = archive.read(ROOT + "readme.txt").decode("utf-8")
                version = header_value(plugin, "Version")
                constant = re.search(r"define\('FALUSS_PLATFORM_VERSION', '([^']+)'\)", plugin)
                if not re.fullmatch(r"\d+\.\d+\.\d+", version) or not constant or constant.group(1) != version:
                    errors.append("Plugin version header and constant do not match")
                for field in ("Requires at least", "Requires PHP", "Tested up to"):
                    value = header_value(plugin, field)
                    if not value or value != header_value(readme, field):
                        errors.append(f"Missing or inconsistent {field} metadata")
                if not readme.startswith("=== Faluss Platform ===\n"):
                    errors.append("Invalid WordPress readme header")
    except (BadZipFile, OSError, UnicodeError) as error:
        errors.append(f"Archive cannot be read: {error}")
    return errors


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("archive", type=Path)
    problems = validate(parser.parse_args().archive)
    for problem in problems:
        print(problem, file=sys.stderr)
    if problems:
        sys.exit(1)
    print("WordPress release archive validated")
