import importlib.util
import tempfile
import unittest
from pathlib import Path
from zipfile import ZIP_DEFLATED, ZipFile, ZipInfo


SCRIPT = Path(__file__).resolve().parents[2] / "scripts" / "validate-release.py"
SPEC = importlib.util.spec_from_file_location("validate_release", SCRIPT)
MODULE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(MODULE)

PLUGIN = b"""<?php
/**
 * Plugin Name: Faluss Platform
 * Version: 0.5.2
 * Requires at least: 7.1
 * Tested up to: 7.1.2
 * Requires PHP: 8.2
 */
define('FALUSS_PLATFORM_VERSION', '0.5.2');
"""
README = b"""=== Faluss Platform ===
Requires at least: 7.1
Tested up to: 7.1.2
Requires PHP: 8.2
Stable tag: trunk

Description.
"""
FILES = {
    "faluss-platform/faluss-platform.php": PLUGIN,
    "faluss-platform/readme.txt": README,
    "faluss-platform/vendor/autoload.php": b"<?php",
    "faluss-platform/vendor/yahnis-elsts/plugin-update-checker/load-v5p7.php": b"<?php",
    "faluss-platform/vendor/stripe/stripe-php/init.php": b"<?php",
}


class ReleaseArchiveTest(unittest.TestCase):
    def write_archive(self, files, modes=None):
        directory = tempfile.TemporaryDirectory()
        self.addCleanup(directory.cleanup)
        path = Path(directory.name) / "plugin.zip"
        with ZipFile(path, "w", ZIP_DEFLATED) as archive:
            for name, content in files.items():
                info = ZipInfo(name)
                info.create_system = 3
                info.external_attr = (modes or {}).get(name, 0o100644) << 16
                archive.writestr(info, content)
        return path

    def test_accepts_a_wordpress_package_with_matching_server_metadata(self):
        self.assertEqual([], MODULE.validate(self.write_archive(FILES)))

    def test_rejects_extra_folder_and_development_files(self):
        nested = {name.replace("faluss-platform/", "release/faluss-platform/", 1): data for name, data in FILES.items()}
        self.assertTrue(any("Invalid archive path" in error for error in MODULE.validate(self.write_archive(nested))))
        with_docs = {**FILES, "faluss-platform/AGENTS.md": b"rules"}
        self.assertTrue(any("Development or unexpected" in error for error in MODULE.validate(self.write_archive(with_docs))))

    def test_rejects_missing_or_inconsistent_metadata(self):
        without_readme = {name: data for name, data in FILES.items() if name != "faluss-platform/readme.txt"}
        self.assertTrue(any("Missing required file" in error for error in MODULE.validate(self.write_archive(without_readme))))
        bad_readme = {**FILES, "faluss-platform/readme.txt": README.replace(b"7.1.2", b"7.0")}
        self.assertTrue(any("inconsistent Tested up to" in error for error in MODULE.validate(self.write_archive(bad_readme))))

    def test_rejects_unsafe_permissions_and_development_dependencies(self):
        name = "faluss-platform/faluss-platform.php"
        bad_modes = self.write_archive(FILES, {name: 0o100666})
        self.assertTrue(any("Incorrect permissions" in error for error in MODULE.validate(bad_modes)))
        with_dev = {**FILES, "faluss-platform/vendor/phpunit/phpunit/src/TestCase.php": b"<?php"}
        self.assertTrue(any("Development dependency" in error for error in MODULE.validate(self.write_archive(with_dev))))


if __name__ == "__main__":
    unittest.main()
