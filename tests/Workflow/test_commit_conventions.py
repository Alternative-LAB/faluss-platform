"""Exercise commit validation without GitHub writes."""

import os
from pathlib import Path
import subprocess
import tempfile
import textwrap
import unittest


ROOT = Path(__file__).resolve().parents[2]


def step_script(workflow, name):
    step = (ROOT / '.github/workflows' / workflow).read_text().split('      - name: ' + name, 1)[1]
    return textwrap.dedent(step.split('        run: |\n', 1)[1].split('\n      - name:', 1)[0])


class CommitConventionsTest(unittest.TestCase):
    def validate_subject(self, subject):
        script = step_script('pr-governance.yml', 'Validate changelog and commits')
        with tempfile.TemporaryDirectory() as directory:
            executable = Path(directory) / 'git'
            executable.write_text('''#!/usr/bin/env python3
import os
import sys
print('CHANGELOG.md' if sys.argv[1] == 'diff' else os.environ['TEST_SUBJECT'])
''')
            executable.chmod(0o755)
            env = dict(os.environ, PATH=directory + os.pathsep + os.environ['PATH'],
                       BASE_SHA='base', HEAD_SHA='head', TEST_SUBJECT=subject)
            return subprocess.run(['bash', '-c', script], env=env, capture_output=True, text=True)

    def test_accepts_unicode_or_shortcode_and_english_message(self):
        for subject in [':memo: Document project rules', ':bug: Fix release retries',
                        ':bookmark: Prepare Faluss Platform v1.2.3',
                        '📝 Document project rules', '🐛 Fix release retries',
                        '🔖 Prepare Faluss Platform v1.2.3']:
            with self.subTest(subject=subject):
                result = self.validate_subject(subject)
                self.assertEqual(result.returncode, 0, result.stdout + result.stderr)

    def test_rejects_missing_gitmoji_or_incomplete_subject(self):
        for subject in ['Document rules', ':memo:', '📝', '📝 document rules',
                        ':memo: document rules', ':memo: ', ':memo Document rules']:
            with self.subTest(subject=subject):
                self.assertNotEqual(self.validate_subject(subject).returncode, 0)


if __name__ == '__main__':
    unittest.main()
