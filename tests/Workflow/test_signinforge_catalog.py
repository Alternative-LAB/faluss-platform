"""Read the generated deployment catalogue through the standard gettext reader."""

import gettext
import json
from pathlib import Path
import subprocess
import tempfile
import unittest


ROOT = Path(__file__).resolve().parents[2]
SOURCE = ROOT / 'docs/deploiement/signinforge/fr-frontend.json'
COMPILER = ROOT / 'scripts/compile-signinforge-translations.py'


class SigninforgeCatalogTest(unittest.TestCase):
    def setUp(self):
        directory = tempfile.TemporaryDirectory()
        self.addCleanup(directory.cleanup)
        destination = Path(directory.name) / 'fr.mo'
        subprocess.run(['python3', str(COMPILER), str(SOURCE), str(destination)], check=True)
        with destination.open('rb') as stream:
            self.translations = gettext.GNUTranslations(stream)

    def test_public_strings_survive_utf8_compilation(self):
        catalog = json.loads(SOURCE.read_text())
        for source, translation in catalog.items():
            if '\x04' not in source and not source.startswith('Too many failed login attempts.'):
                with self.subTest(source=source):
                    self.assertEqual(self.translations.gettext(source), translation)
        self.assertEqual(self.translations.gettext('Password'), 'Mot de passe')

    def test_contexts_remain_distinct(self):
        catalog = json.loads(SOURCE.read_text())
        contexts = [key for key in catalog if '\x04' in key]
        self.assertTrue(contexts, 'The catalogue must exercise contextual translations')
        for key in contexts:
            context, message = key.split('\x04')
            self.assertEqual(self.translations.pgettext(context, message), catalog[key])

    def test_french_plural_rule_handles_zero_one_and_many(self):
        singular = 'Too many failed login attempts. Please try again in %d minute.'
        plural = 'Too many failed login attempts. Please try again in %d minutes.'
        catalog = json.loads(SOURCE.read_text())
        for count in (0, 1, 2, 5):
            with self.subTest(count=count):
                self.assertEqual(self.translations.ngettext(singular, plural, count),
                                 catalog[singular if count < 2 else plural])

    def test_invalid_cli_usage_fails(self):
        result = subprocess.run(['python3', str(COMPILER)], capture_output=True, text=True)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('Usage:', result.stderr)


if __name__ == '__main__':
    unittest.main()
