"""Exercise the CI PHP lint command against tracked and untracked fixtures."""

from pathlib import Path
import subprocess
import tempfile
import unittest


ROOT = Path(__file__).resolve().parents[2]


class PhpSyntaxTest(unittest.TestCase):
    def lint(self, path, content, untracked=False):
        workflow = (ROOT / '.github/workflows/php-quality.yml').read_text()
        command = workflow.split('      - name: PHP syntax\n', 1)[1].split('        run: ', 1)[1].split('\n', 1)[0]
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            for folder in ('src', 'tests'):
                (root / folder).mkdir()
            (root / 'faluss-platform.php').write_text('<?php echo "valid";')
            fixture = root / path
            fixture.parent.mkdir(parents=True, exist_ok=True)
            fixture.write_text(content)
            subprocess.run(['git', 'init', '-q', directory], check=True)
            subprocess.run(['git', 'add', '.'], cwd=root, check=True)
            if untracked:
                (root / 'vendor').mkdir()
                (root / 'vendor/invalid.php').write_text('<?php syntax error')
            return subprocess.run(['bash', '-eo', 'pipefail', '-c', command], cwd=root,
                                  capture_output=True, text=True)

    def test_rejects_invalid_php_in_every_owned_location(self):
        for path in ('src/Invalid.php', 'tests/Invalid.php', 'scripts/invalid.php',
                     'docs/deploiement/invalid.php', 'other-entrypoint.php'):
            with self.subTest(path=path):
                self.assertNotEqual(self.lint(path, '<?php syntax error').returncode, 0)

    def test_accepts_valid_tracked_php_including_spaces(self):
        result = self.lint('scripts/a script.php', '<?php echo "valid";')
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)

    def test_ignores_untracked_dependencies(self):
        result = self.lint('src/Valid.php', '<?php echo "valid";', untracked=True)
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)


if __name__ == '__main__':
    unittest.main()
